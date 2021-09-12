<?php

namespace App\ServiceProvider;

use Flarum\Foundation\AbstractServiceProvider;
use Illuminate\Container\Container;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

class ErrorLogProvider extends AbstractServiceProvider
{
    public function register()
    {
        $this->container->extend('log', function (Logger $logger, Container $app): Logger {
            $handler = new StreamHandler('php://stderr');

            if ($app['flarum.config']->inDebugMode()) {
                $logger->pushHandler($handler);
            } else {
                $logger->setHandlers([$handler]);
            }

            return $logger;
        });
    }
}
