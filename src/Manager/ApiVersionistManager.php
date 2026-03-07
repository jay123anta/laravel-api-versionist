<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Manager;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Versionist\ApiVersionist\Events\RequestUpgraded;
use Versionist\ApiVersionist\Events\ResponseDowngraded;
use Versionist\ApiVersionist\Pipeline\RequestUpgradePipeline;
use Versionist\ApiVersionist\Pipeline\ResponseDowngradePipeline;
use Versionist\ApiVersionist\Registry\TransformerRegistry;
use Versionist\ApiVersionist\Support\VersionParser;
use Versionist\ApiVersionist\Version\VersionNegotiator;

/**
 * Central orchestrator for the API versioning lifecycle.
 *
 * Coordinates version negotiation, request upgrading, response downgrading,
 * header injection, and event dispatching. All dependencies are injected
 * through the constructor — no static calls or facades.
 *
 * ## Lifecycle (managed by the middleware)
 *
 * ```
 * 1. negotiate(Request)              → resolve effective client version
 * 2. upgradeRequest(Request, $ver)   → transform request payload to latest
 * 3. → controller handles request using the latest schema
 * 4. downgradeResponse(Response, $v) → transform response back to client version
 * ```
 *
 * ## Envelope support
 *
 * When `request_data_key` or `response_data_key` is configured, the manager
 * extracts the nested data from the envelope, transforms it, and writes it
 * back into the same envelope key — leaving the rest of the payload intact.
 */
final class ApiVersionistManager
{
    /**
     * Create a new ApiVersionistManager instance.
     *
     * @param  VersionNegotiator         $negotiator         The version negotiation engine.
     * @param  RequestUpgradePipeline    $upgradePipeline    The request upgrade pipeline.
     * @param  ResponseDowngradePipeline $downgradePipeline  The response downgrade pipeline.
     * @param  TransformerRegistry       $registry           The transformer registry.
     * @param  Dispatcher                $events             The Laravel event dispatcher.
     * @param  array<string, mixed>      $config             The api-versionist configuration.
     */
    public function __construct(
        private readonly VersionNegotiator $negotiator,
        private readonly RequestUpgradePipeline $upgradePipeline,
        private readonly ResponseDowngradePipeline $downgradePipeline,
        private readonly TransformerRegistry $registry,
        private readonly Dispatcher $events,
        private readonly array $config,
    ) {
    }

    /**
     * Negotiate the effective API version for the incoming request.
     *
     * Delegates to the VersionNegotiator which tries configured detection
     * strategies and applies strict-mode / fallback rules.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @return string             The normalized, resolved API version string.
     *
     * @throws \Versionist\ApiVersionist\Exceptions\UnknownVersionException
     *         In strict mode when the detected version is not registered.
     */
    public function negotiate(Request $request): string
    {
        return $this->negotiator->negotiate($request);
    }

    /**
     * Upgrade the request payload from the client's version to the latest.
     *
     * Extracts the transformable data (respecting `request_data_key` for
     * enveloped payloads), runs it through the upgrade pipeline, merges
     * the result back, and fires the {@see RequestUpgraded} event.
     *
     * If the client version already matches the latest version, this is
     * a no-op — the request is returned unmodified and no event fires.
     *
     * @param  Request  $request        The incoming HTTP request.
     * @param  string   $clientVersion  The negotiated client API version.
     * @return Request                  The request with upgraded payload merged in.
     */
    public function upgradeRequest(Request $request, string $clientVersion): Request
    {
        $latestVersion = $this->latestVersion();

        // Same version → nothing to transform.
        if (VersionParser::compare($clientVersion, $latestVersion) >= 0) {
            return $request;
        }

        $requestDataKey = $this->config['request_data_key'] ?? null;
        $allInput       = $request->all();

        // ── Extract the transformable portion ──
        // If a request_data_key is set, only that nested array is transformed;
        // the rest of the payload is left untouched. If null, the entire
        // request body is the transformable data.
        $originalData = $requestDataKey !== null
            ? (array) ($allInput[$requestDataKey] ?? [])
            : $allInput;

        // ── Run the upgrade pipeline ──
        $upgradedData = $this->upgradePipeline->run(
            $originalData,
            $clientVersion,
            $latestVersion,
        );

        // ── Write the upgraded data back into the request ──
        // When using an envelope key, merge the full input (with the
        // upgraded nested key) so non-envelope keys remain intact.
        // When transforming the entire body (no envelope), replace the
        // request input entirely so keys removed by transformers (e.g.
        // 'name' renamed to 'full_name') don't linger in the payload.
        if ($requestDataKey !== null) {
            $allInput[$requestDataKey] = $upgradedData;
            $request->replace($allInput);
        } else {
            $request->replace($upgradedData);
        }

        // ── Fire the event ──
        $this->events->dispatch(new RequestUpgraded(
            request:      $request,
            fromVersion:  $clientVersion,
            toVersion:    $latestVersion,
            originalData: $originalData,
            upgradedData: $upgradedData,
        ));

        return $request;
    }

    /**
     * Downgrade the response payload from the latest version to the client's version.
     *
     * Extracts the transformable data (respecting `response_data_key` for
     * enveloped payloads), runs it through the downgrade pipeline, writes
     * the result back into the response, injects version/deprecation headers,
     * and fires the {@see ResponseDowngraded} event.
     *
     * If the client version already matches the latest version, only headers
     * are added — no data transformation occurs and no event fires.
     *
     * @param  JsonResponse  $response       The outgoing JSON response.
     * @param  string        $clientVersion  The negotiated client API version.
     * @return JsonResponse                  The response with downgraded payload and headers.
     */
    public function downgradeResponse(JsonResponse $response, string $clientVersion): JsonResponse
    {
        $latestVersion = $this->latestVersion();

        // ── Always inject version headers (even when no transformation) ──
        $this->applyVersionHeaders($response, $clientVersion);

        // Same version → no data transformation needed.
        if (VersionParser::compare($clientVersion, $latestVersion) >= 0) {
            return $response;
        }

        $responseDataKey = $this->config['response_data_key'] ?? null;

        /** @var array<string, mixed> $fullPayload */
        $fullPayload = (array) $response->getData(true);

        // ── Extract the transformable portion ──
        // If a response_data_key is set, only that nested array is transformed.
        // This supports common envelope patterns like { "data": {...}, "meta": {...} }.
        $originalData = $responseDataKey !== null
            ? (array) ($fullPayload[$responseDataKey] ?? [])
            : $fullPayload;

        // ── Run the downgrade pipeline ──
        $downgradedData = $this->downgradePipeline->run(
            $originalData,
            $latestVersion,
            $clientVersion,
        );

        // ── Write the downgraded data back into the response ──
        if ($responseDataKey !== null) {
            $fullPayload[$responseDataKey] = $downgradedData;
            $response->setData($fullPayload);
        } else {
            $response->setData($downgradedData);
        }

        // ── Fire the event ──
        $this->events->dispatch(new ResponseDowngraded(
            response:       $response,
            fromVersion:    $latestVersion,
            toVersion:      $clientVersion,
            originalData:   $originalData,
            downgradedData: $downgradedData,
        ));

        return $response;
    }

    /**
     * Return the transformer registry instance.
     *
     * Useful for introspection — e.g. listing registered versions in
     * a changelog controller.
     *
     * @return TransformerRegistry
     */
    public function getRegistry(): TransformerRegistry
    {
        return $this->registry;
    }

    /**
     * Return the resolved package configuration array.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Apply version identification and deprecation headers to the response.
     *
     * Conditionally adds headers based on the `add_version_headers` config
     * flag. When enabled, always sets X-Api-Version and X-Api-Latest-Version.
     * For deprecated versions, additionally sets Deprecation, Sunset, and
     * Link headers per RFC 8594.
     *
     * @param  JsonResponse  $response       The response to decorate.
     * @param  string        $clientVersion  The negotiated client API version.
     * @return void
     */
    private function applyVersionHeaders(JsonResponse $response, string $clientVersion): void
    {
        if (! ($this->config['add_version_headers'] ?? true)) {
            return;
        }

        $latestVersion = $this->latestVersion();

        // Get the full header set from the negotiator (includes
        // X-Api-Version, X-Api-Latest-Version, and conditional
        // Deprecation + Sunset headers).
        $headers = $this->negotiator->buildDeprecationHeaders(
            $clientVersion,
            $latestVersion,
        );

        // If the version is deprecated and a changelog endpoint is configured,
        // add an RFC 8288 Link header pointing to the migration guide / changelog.
        if ($this->negotiator->isDeprecated($clientVersion)) {
            $changelog = $this->config['changelog'] ?? [];

            if (! empty($changelog['enabled']) && ! empty($changelog['endpoint'])) {
                $headers['Link'] = sprintf(
                    '<%s>; rel="successor-version"',
                    $changelog['endpoint'],
                );
            }
        }

        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }
    }

    /**
     * Resolve the latest API version string from configuration.
     *
     * Falls back to the registry's latest version if the config key
     * is not set, ensuring the manager works even with minimal config.
     *
     * @return string  The normalized latest version string.
     */
    private function latestVersion(): string
    {
        $configured = $this->config['latest_version'] ?? null;

        if ($configured !== null && VersionParser::isValid($configured)) {
            return VersionParser::parse($configured);
        }

        return $this->registry->latestVersion();
    }
}
