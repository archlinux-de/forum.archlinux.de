<?php

namespace App\ServiceProvider;

use Flarum\Foundation\AbstractServiceProvider;
use Illuminate\Container\Container;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\SyslogHandler;
use Monolog\Logger;

class SyslogServiceProvider extends AbstractServiceProvider
{
    public function register()
    {
        $this->container->extend('log', function (Logger $logger, Container $app): Logger {
            $handler = new SyslogHandler(ident: 'flarum', level: Logger::INFO);
            $handler->setFormatter(new LineFormatter(allowInlineLineBreaks: true, ignoreEmptyContextAndExtra: true));

            if ($app['flarum.config']->inDebugMode()) {
                $logger->pushHandler($handler);
            } else {
                $logger->setHandlers([$handler]);
            }

            return $logger;
        });
    }
}
