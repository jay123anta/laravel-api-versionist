<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Versionist\ApiVersionist\Manager\ApiVersionistManager;

/**
 * Negotiates version, upgrades the request, lets the controller handle it,
 * then downgrades the response back to the client's version.
 *
 * Non-JSON responses (downloads, redirects) pass through untouched.
 */
final class ApiVersionMiddleware
{
    public function __construct(
        private readonly ApiVersionistManager $manager,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $clientVersion = $this->manager->negotiate($request);
        $request->attributes->set('api_version', $clientVersion);
        $request = $this->manager->upgradeRequest($request, $clientVersion);

        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $response = $this->manager->downgradeResponse($response, $clientVersion);
        }

        return $response;
    }
}
