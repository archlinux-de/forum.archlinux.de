<?php

/*
 * This file is part of Flarum.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

use App\Console\EnableExtensions;
use App\Middleware\ContentSecurityPolicy;
use App\ServiceProvider\SessionServiceProvider;
use App\ServiceProvider\ErrorLogProvider;
use Flarum\Extend;

return [
    (new Extend\Console())->command(EnableExtensions::class),
    (new Extend\ServiceProvider())->register(ErrorLogProvider::class),
    (new Extend\ServiceProvider())->register(SessionServiceProvider::class),
    (new Extend\Middleware('forum'))->add(ContentSecurityPolicy::class)
];
