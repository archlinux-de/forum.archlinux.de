<?php

namespace App\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Database\ConnectionInterface;

class PurgeOrphanedAvatars extends Command
{
    protected $signature = 'app:purge-orphaned-avatars';
    protected $description = 'Delete avatar files that are not linked to any user.';

    public function handle(Factory $filesystemFactory, ConnectionInterface $db): void
    {
        $disk = $filesystemFactory->disk('flarum-avatars');

        /** @var string[] $referencedAvatars */
        $referencedAvatars = $db->table('users')
            ->whereNotNull('avatar_url')
            ->pluck('avatar_url')
            ->all();

        /** @var string[] $files */
        $files = $disk->files();

        $orphaned = array_diff($files, $referencedAvatars);

        foreach ($orphaned as $file) {
            $disk->delete($file);
        }
    }
}
