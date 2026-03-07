<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Facades;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Versionist\ApiVersionist\Manager\ApiVersionistManager;
use Versionist\ApiVersionist\Registry\TransformerRegistry;

/**
 * Facade for the {@see ApiVersionistManager}.
 *
 * Provides static-like access to the central API versioning orchestrator.
 * All method calls are proxied to the singleton ApiVersionistManager
 * instance resolved from the container.
 *
 * @method static string           negotiate(Request $request)                              Negotiate the effective API version for the incoming request.
 * @method static Request          upgradeRequest(Request $request, string $clientVersion)  Upgrade the request payload from the client's version to the latest.
 * @method static JsonResponse     downgradeResponse(JsonResponse $response, string $clientVersion) Downgrade the response payload from the latest version to the client's version.
 * @method static TransformerRegistry getRegistry()                                         Return the transformer registry instance.
 * @method static array            getConfig()                                              Return the resolved package configuration array.
 *
 * @see \Versionist\ApiVersionist\Manager\ApiVersionistManager
 */
final class ApiVersionist extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'api-versionist';
    }
}
