<?php

namespace App\Console;

use Exception;
use Flarum\Console\AbstractCommand;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Flarum\Post\PostRepository;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class BackupImages extends AbstractCommand
{
    /**
     * Hosts that respond with 200 instead of 404 or no longer exist
     * @var string[]
     */
    private array $brokenHosts = [
        'abload.de',
        'bilder-hochladen.net',
        'directupload.net',
        'forum.archlinux.de', // @TODO: look for backups
        'imagebanana.com',
        'imageshack.com',
        'imageshack.se',
        'imageshack.us',
        'imagevenue.com',
        'ompldr.org',
        'omploader.org',
        'paste.archlinux.de', // @TODO: look for backups
        'picfront.org',
        'tinypic.com',
    ];

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
            'timeout' => 15,
            'headers' => [
                'Accept' => 'image/*',
                'User-Agent' => 'Mozilla/5.0'
            ]
        ]);

        $requests = function () use ($posts, $failedUrls, $backupDir) {
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
                    if (str_contains($url, 'postimg.org')) {
                        $url = str_replace('postimg.org', 'postimg.cc', $url);
                    }

                    if (in_array($url, $failedUrls)) {
                        $this->error($url . ' skip previously failed url');
                        continue;
                    }

                    $host = parse_url($url, PHP_URL_HOST);
                    assert(is_string($host));

                    $path = parse_url($url, PHP_URL_PATH);
                    assert(is_string($path));

                    $pathInfo = pathinfo($path);
                    $filename = $pathInfo['basename'];

                    if (empty($filename)) {
                        $this->error("Skipping URL without filename: $url");
                        continue;
                    }

                    $filepath = $backupDir . $host . '/' . ltrim($path, '/');

                    if (file_exists($filepath)) {
                        $this->info($url . ' already downloaded');
                        continue;
                    }

                    yield [$url, $filepath, $host] => new Request('GET', $url);
                }
            }
        };

        $pool = new Pool($httpClient, $requests(), [
            'concurrency' => 32,
            'fulfilled' => function (ResponseInterface $response, array $index) use ($failedLog) {
                list($url, $filepath) = $index;
                assert(is_string($url));
                assert(is_string($filepath));
                try {
                    $this->saveImage($response, $url, $filepath);
                } catch (RuntimeException $e) {
                    file_put_contents($failedLog, $url . "\n", FILE_APPEND);
                    $this->error("Failed (invalid content): $url");
                }
            },
            'rejected' => function (\Throwable $e, array $index) use ($httpClient, $failedLog) {
                list($url, $filepath, $host) = $index;
                assert(is_string($url));
                assert(is_string($filepath));
                assert(is_string($host));

                if ($this->isBrokenHost($host)) {
                    try {
                        $response = $this->fetchImage($httpClient, $url, true);
                        $this->saveImage($response, $url, $filepath);
                        return; // Fallback success
                    } catch (Exception $fallbackException) {
                        // Fallback failed, fall through to final logging
                    }
                }

                // Log any request that was rejected and not recovered by the fallback.
                file_put_contents($failedLog, $url . "\n", FILE_APPEND);
                $this->error("Failed (download error): $url");
            },
        ]);

        $promise = $pool->promise();
        $promise->wait();
    }

    private function saveImage(ResponseInterface $response, string $url, string $filepath): void
    {
        $imageString = $response->getBody()->getContents();

        $fi = finfo_open();
        assert(!!$fi);
        $type = $fi->buffer($imageString, FILEINFO_MIME_TYPE);
        assert(is_string($type));
        finfo_close($fi);
        if (!str_starts_with($type, 'image/')) {
            throw new RuntimeException(sprintf('Invalid type %s', $type));
        }

        $pathInfo = pathinfo($filepath);
        assert(array_key_exists('dirname', $pathInfo));
        $dirname = $pathInfo['dirname'];

        if (!is_dir($dirname) && !@mkdir($dirname, 0777, true) && !is_dir($dirname)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $dirname));
        }

        file_put_contents($filepath, $imageString);
        $lastModified = $response->getHeader('last-modified');
        if ($lastModified && count($lastModified) == 1) {
            touch($filepath, strtotime($lastModified[0]) ?: null);
        }

        $this->info($url . ' downloaded');
    }

    private function fetchImage(Client $httpClient, string $url, bool $forceArchive = false): ResponseInterface
    {
        if (!$forceArchive) {
            return $httpClient->get($url);
        }

        $waybackApi = 'https://archive.org/wayback/available?url=' . urlencode($url);
        try {
            $response = $httpClient->get($waybackApi);
            $json = json_decode($response->getBody()->getContents(), true);

            assert(is_array($json));
            assert(is_array($json['archived_snapshots']));
            if (empty($json['archived_snapshots'])) {
                throw new RuntimeException('No archived snapshot available for URL: ' . $url);
            }
            assert(is_array($json['archived_snapshots']['closest']));
            assert(is_string($json['archived_snapshots']['closest']['url']));
            if (!empty($json['archived_snapshots']['closest']['url'])) {
                $archivedUrl = $json['archived_snapshots']['closest']['url'];
                return $httpClient->get($archivedUrl);
            }
        } catch (Exception $e) {
            throw new RuntimeException('Failed to retrieve image from archive.org.', 0, $e);
        }

        throw new RuntimeException('No archived snapshot available for URL: ' . $url);
    }

    private function isBrokenHost(string $host): bool
    {
        return array_any($this->brokenHosts, fn($brokenHost) => str_ends_with($host, $brokenHost));
    }
}
