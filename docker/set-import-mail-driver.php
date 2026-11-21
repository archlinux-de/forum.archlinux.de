<?php

use Flarum\Settings\DatabaseSettingsRepository;
use Illuminate\Database\Capsule\Manager;

require __DIR__ . '/../vendor/autoload.php';

/** @var array{database: array{prefix?: string}} $config */
$config = require __DIR__ . '/../config.php';

// Use the local import connection before booting any restored extensions.
$database = new Manager();
$database->addConnection([
    'driver' => 'mariadb',
    'host' => 'mariadb',
    'database' => $argv[1] ?? throw new InvalidArgumentException('Missing import database name.'),
    'username' => 'root',
    'prefix' => $config['database']['prefix'] ?? '',
]);

$settings = new DatabaseSettingsRepository($database->getConnection());
$settings->set('mail_driver', 'log');
