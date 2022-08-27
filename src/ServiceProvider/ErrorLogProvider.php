<?php

namespace App\ServiceProvider;

use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Foundation\Config;
use Illuminate\Container\Container;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Logger;

class ErrorLogProvider extends AbstractServiceProvider
{
    public function register()
    {
        $this->container->extend('log', function (Logger $logger, Container $app): Logger {
            $handler = new ErrorLogHandler();

            if (
                $app['flarum.config'] &&
                $app['flarum.config'] instanceof Config &&
                $app['flarum.config']->inDebugMode()
            ) {
                $logger->pushHandler($handler);
            } else {
                $logger->setHandlers([$handler]);
            }

            return $logger;
        });
    }
}
