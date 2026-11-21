<?php

namespace App\ServiceProvider;

use Flarum\Foundation\AbstractServiceProvider;
use Illuminate\Support\Collection;

class LocalEmojiProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->extend('flarum.settings.default', function (Collection $defaults): Collection {
            return $defaults->put('flarum-emoji.cdn', '/assets/extensions/twemoji-assets/');
        });
    }
}
