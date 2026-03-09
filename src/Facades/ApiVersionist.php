<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Facades;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Versionist\ApiVersionist\Manager\ApiVersionistManager;
use Versionist\ApiVersionist\Registry\TransformerRegistry;

/**
 * @method static string negotiate(Request $request)
 * @method static Request upgradeRequest(Request $request, string $clientVersion)
 * @method static JsonResponse downgradeResponse(JsonResponse $response, string $clientVersion)
 * @method static TransformerRegistry getRegistry()
 * @method static array getConfig()
 *
 * @see ApiVersionistManager
 */
final class ApiVersionist extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'api-versionist';
    }
}
