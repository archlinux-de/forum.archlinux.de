<?php

/*
 * This file is part of Flarum.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

use App\Console\EnableExtensions;
use App\ServiceProvider\SessionServiceProvider;
use App\ServiceProvider\SyslogServiceProvider;
use Flarum\Extend;

return [
    (new Extend\Console())->command(EnableExtensions::class),
    (new Extend\ServiceProvider())->register(SyslogServiceProvider::class),
    (new Extend\ServiceProvider())->register(SessionServiceProvider::class),
];
