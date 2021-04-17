<?php

/*
 * This file is part of Flarum.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

use App\Console\EnableExtensions;
use Flarum\Extend;

return [
    (new Extend\Console())->command(EnableExtensions::class),
];
