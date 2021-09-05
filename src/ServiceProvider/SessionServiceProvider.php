<?php

namespace App\ServiceProvider;

use Flarum\Foundation\AbstractServiceProvider;
use Illuminate\Contracts\Config\Repository;

class SessionServiceProvider extends AbstractServiceProvider
{
    public function register()
    {
        /** @var Repository $config */
        $config = $this->container->get('config');
        $config->set('session.lifetime');
    }
}
