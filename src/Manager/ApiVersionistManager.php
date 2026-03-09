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
 * Orchestrates version negotiation, request upgrading, response downgrading,
 * and header injection. Supports envelope mode via request_data_key/response_data_key.
 */
final class ApiVersionistManager
{
    public function __construct(
        private readonly VersionNegotiator $negotiator,
        private readonly RequestUpgradePipeline $upgradePipeline,
        private readonly ResponseDowngradePipeline $downgradePipeline,
        private readonly TransformerRegistry $registry,
        private readonly Dispatcher $events,
        private readonly array $config,
    ) {
    }

    public function negotiate(Request $request): string
    {
        return $this->negotiator->negotiate($request);
    }

    public function upgradeRequest(Request $request, string $clientVersion): Request
    {
        $latestVersion = $this->latestVersion();

        if (VersionParser::compare($clientVersion, $latestVersion) >= 0) {
            return $request;
        }

        $requestDataKey = $this->config['request_data_key'] ?? null;
        $allInput       = $request->all();

        $originalData = $requestDataKey !== null
            ? (array) ($allInput[$requestDataKey] ?? [])
            : $allInput;

        $upgradedData = $this->upgradePipeline->run(
            $originalData,
            $clientVersion,
            $latestVersion,
        );

        if ($requestDataKey !== null) {
            $allInput[$requestDataKey] = $upgradedData;
            $request->replace($allInput);
        } else {
            $request->replace($upgradedData);
        }

        $this->events->dispatch(new RequestUpgraded(
            request:      $request,
            fromVersion:  $clientVersion,
            toVersion:    $latestVersion,
            originalData: $originalData,
            upgradedData: $upgradedData,
        ));

        return $request;
    }

    public function downgradeResponse(JsonResponse $response, string $clientVersion): JsonResponse
    {
        $latestVersion = $this->latestVersion();
        $this->applyVersionHeaders($response, $clientVersion);

        if (VersionParser::compare($clientVersion, $latestVersion) >= 0) {
            return $response;
        }

        $responseDataKey = $this->config['response_data_key'] ?? null;

        $fullPayload = (array) $response->getData(true);

        $originalData = $responseDataKey !== null
            ? (array) ($fullPayload[$responseDataKey] ?? [])
            : $fullPayload;

        $downgradedData = $this->downgradePipeline->run(
            $originalData,
            $latestVersion,
            $clientVersion,
        );

        if ($responseDataKey !== null) {
            $fullPayload[$responseDataKey] = $downgradedData;
            $response->setData($fullPayload);
        } else {
            $response->setData($downgradedData);
        }

        $this->events->dispatch(new ResponseDowngraded(
            response:       $response,
            fromVersion:    $latestVersion,
            toVersion:      $clientVersion,
            originalData:   $originalData,
            downgradedData: $downgradedData,
        ));

        return $response;
    }

    public function getRegistry(): TransformerRegistry
    {
        return $this->registry;
    }

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return $this->config;
    }

    private function applyVersionHeaders(JsonResponse $response, string $clientVersion): void
    {
        if (! ($this->config['add_version_headers'] ?? true)) {
            return;
        }

        $latestVersion = $this->latestVersion();

        $headers = $this->negotiator->buildDeprecationHeaders(
            $clientVersion,
            $latestVersion,
        );

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

    private function latestVersion(): string
    {
        $configured = $this->config['latest_version'] ?? null;

        if ($configured !== null && VersionParser::isValid($configured)) {
            return VersionParser::parse($configured);
        }

        return $this->registry->latestVersion();
    }
}
