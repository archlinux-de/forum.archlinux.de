<?php

namespace App\Middleware;

use Flarum\Http\RequestUtil;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ContentSecurityPolicy implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $policies = [
            'default-src' => ["'self'"],
            'img-src' => ["'self'", "data:", '*'],
            'script-src' => ["'self'", "'unsafe-inline'"],
            'style-src' => ["'self'", "'unsafe-inline'"],
            'connect-src' => ["'self'"],
            'form-action' => ["'self'"],
            'object-src' => ["'none'"],
            'frame-ancestors' => ["'none'"]
        ];

        if (!RequestUtil::getActor($request)->isGuest()) {
            $policies['img-src'][] = 'i.imgur.com';
            $policies['connect-src'][] = 'api.imgur.com';
        }

        return $response->withAddedHeader(
            'Content-Security-Policy',
            $this->createHeaderValue($policies)
        );
    }

    private function createHeaderValue(array $policies): string
    {
        $policies = array_map(fn(array $values): string => implode(' ', $values), $policies);
        array_walk($policies, fn(string &$values, string $key): string => $values = $key . ' ' . $values);

        return implode('; ', $policies);
    }
}
