<?php

namespace App\ServiceProvider;

use Flarum\Foundation\AbstractServiceProvider;
use Illuminate\Cache\ApcStore;
use Illuminate\Cache\ApcWrapper;
use Illuminate\Contracts\Cache\Store;

class ApcuCacheProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton('cache.filestore', fn (): ApcStore => new ApcStore(new ApcWrapper(), 'flarum_'));
        $this->container->alias('cache.filestore', Store::class);
    }
}
