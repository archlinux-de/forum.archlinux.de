<?php

namespace App\Middleware;

use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class GuestApiCacheHeader implements MiddlewareInterface
{
    private const TTL = 60;
    private const PREFIX = 'api_';

    public function __construct(private Cache $cache)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() !== 'GET' || !RequestUtil::getActor($request)->isGuest()) {
            return $handler->handle($request);
        }

        $cacheKey = self::PREFIX . md5($request->getUri()->getPath() . '?' . $request->getUri()->getQuery());

        /** @var ?string $cached */
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return new JsonResponse(
                json_decode($cached, true, 512, JSON_THROW_ON_ERROR),
                200,
                ['content-type' => 'application/vnd.api+json', 'X-Cache' => 'HIT']
            );
        }

        $response = $handler->handle($request);

        if ($response->getStatusCode() === 200) {
            $this->cache->put($cacheKey, (string) $response->getBody(), self::TTL);
        }

        return $response->withHeader('X-Cache', 'MISS');
    }
}
