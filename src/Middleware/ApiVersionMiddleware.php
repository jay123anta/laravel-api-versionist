<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Versionist\ApiVersionist\Manager\ApiVersionistManager;

/**
 * HTTP middleware that intercepts API traffic for automatic version
 * transformation.
 *
 * ## Request lifecycle
 *
 * ```
 * Incoming Request
 *   │
 *   ▼
 * 1. Negotiate version (detect from request, validate against registry)
 * 2. Store version in request attributes (api_version)
 * 3. Upgrade request payload from client version → latest version
 *   │
 *   ▼
 * Controller (always sees the latest schema)
 *   │
 *   ▼
 * 4. If response is JsonResponse:
 *    Downgrade response payload from latest → client version
 *    Inject version/deprecation headers
 * 5. If response is NOT JsonResponse:
 *    Pass through completely untouched
 *   │
 *   ▼
 * Outgoing Response
 * ```
 *
 * Register this middleware with the alias `api.version` in the kernel
 * or via the service provider.
 */
final class ApiVersionMiddleware
{
    /**
     * Create a new ApiVersionMiddleware instance.
     *
     * @param  ApiVersionistManager  $manager  The central API versioning manager.
     */
    public function __construct(
        private readonly ApiVersionistManager $manager,
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * Negotiates the API version, upgrades the request payload, passes
     * control to the next middleware/controller, and then conditionally
     * downgrades the response if it is a JsonResponse.
     *
     * Non-JsonResponse responses (file downloads, redirects, streamed
     * responses, etc.) pass through completely untouched.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @param  Closure  $next     The next middleware or controller handler.
     * @return Response           The outgoing HTTP response.
     *
     * @throws \Versionist\ApiVersionist\Exceptions\UnknownVersionException
     *         In strict mode when the detected version is not registered.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // ── Step 1: Negotiate the effective API version ──
        // Runs detection strategies and validates against the registry.
        // In strict mode, may throw UnknownVersionException.
        $clientVersion = $this->manager->negotiate($request);

        // ── Step 2: Store version in request attributes ──
        // This makes the version accessible to controllers, form requests,
        // and other middleware via $request->attributes->get('api_version')
        // or the $request->apiVersion() macro (if registered).
        $request->attributes->set('api_version', $clientVersion);

        // ── Step 3: Upgrade request payload to the latest version ──
        // Transforms the request data through the upgrade chain so the
        // controller always works with the latest schema. If already at
        // the latest version, this is a no-op.
        $request = $this->manager->upgradeRequest($request, $clientVersion);

        // ── Step 4: Pass to the next handler (controller) ──
        $response = $next($request);

        // ── Step 5: Downgrade response if it's a JSON response ──
        // Only JsonResponse instances are eligible for transformation.
        // File downloads, redirects, streamed responses, and other
        // response types pass through completely untouched.
        if ($response instanceof JsonResponse) {
            $response = $this->manager->downgradeResponse($response, $clientVersion);
        }

        return $response;
    }
}
