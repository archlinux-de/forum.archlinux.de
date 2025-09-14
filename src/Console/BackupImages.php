<?php

namespace App\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Flarum\Post\PostRepository;
use GuzzleHttp\Client;

class BackupImages extends AbstractCommand
{
    public function __construct(private readonly PostRepository $postRepository)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('app:backup-images');
    }

    protected function fire(): void
    {
        ini_set('memory_limit', '4G');

        $backupDir = __DIR__ . '/../../storage/image-backup/';
        if (!is_dir($backupDir)) {
            mkdir($backupDir);
        }

        $failedLog = $backupDir . '/failed.log';
        $failedUrls = [];
        if (file_exists($failedLog)) {
            $failedUrls = file($failedLog, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES);
            assert(is_array($failedUrls));
        }

        $posts = $this->postRepository->query();
        $httpClient = new Client([
            'timeout' => 10,
            'headers' => [
                'Accept' => 'image/*',
                'User-Agent' => 'Mozilla/5.0'
            ]
        ]);

        // Hosts that respond with 200 instead of 404 or no longer exist
        $brokenHosts = [
            'tinypic.com',
            'imagevenue.com',
            'picfront.org',
            'imageshack.us',
            'imageshack.com',
            'imageshack.se',
            'ompldr.org',
            'omploader.org',
            'imagebanana.com',
            'postimg.org',
            'forum.archlinux.de',
            'paste.archlinux.de'
        ];

        /** @var Post $post */
        foreach ($posts->get() as $post) {
            if (!$post instanceof CommentPost) {
                continue;
            }

            $content = $post->getParsedContentAttribute();

            if (!preg_match_all('/<IMG.+?src="(https?:\/\/.+?)">/si', $content, $matches)) {
                continue;
            }

            foreach ($matches[1] as $url) {
                if (in_array($url, $failedUrls)) {
                    $this->error($url . ' skip previously failed url');
                    continue;
                }

                try {
                    $host = parse_url($url, PHP_URL_HOST);
                    assert(is_string($host));

                    foreach ($brokenHosts as $brokenHost) {
                        if (str_ends_with($host, $brokenHost)) {
                            $this->error($url . ' skip broken host');
                            continue 2;
                        }
                    }

                    $path = parse_url($url, PHP_URL_PATH);
                    assert(is_string($path));
                    $pathInfo = pathinfo($path);
                    assert(array_key_exists('basename', $pathInfo));
                    $filename = $pathInfo['basename'];
                    assert(array_key_exists('dirname', $pathInfo));
                    $dirname = $pathInfo['dirname'];
                    $filepath = $backupDir . $host . '/' . $dirname;

                    if (file_exists($filepath . '/' . $filename)) {
                        $this->info($url . ' already downloaded');
                        continue;
                    }

                    $response = $httpClient->get($url);
                    $imageString = $response->getBody()->getContents();

                    $fi = finfo_open();
                    assert(!!$fi);
                    $type = $fi->buffer($imageString, FILEINFO_MIME_TYPE);
                    assert(is_string($type));
                    finfo_close($fi);
                    if (!str_starts_with($type, 'image/')) {
                        throw new \RuntimeException(sprintf('Invalid type %s', $type));
                    }

                    if (!is_dir($filepath)) {
                        mkdir($filepath, recursive: true);
                    }

                    file_put_contents($filepath . '/' . $filename, $imageString);
                    $lastModified = $response->getHeader('last-modified');
                    if ($lastModified && count($lastModified) == 1) {
                        touch($filepath . '/' . $filename, strtotime($lastModified[0]) ?: null);
                    }

                    $this->info($url . ' downloaded');
                } catch (\RuntimeException $e) {
                    file_put_contents($failedLog, $url . "\n", FILE_APPEND);
                    $this->error($url . ' failed to download');
                }
            }
        }
    }
}
