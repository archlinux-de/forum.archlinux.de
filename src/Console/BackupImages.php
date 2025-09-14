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
use Illuminate\Database\Eloquent\Collection;
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

    private Client $httpClient;
    private string $backupDir;
    private string $failedLog;

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

        $this->backupDir = __DIR__ . '/../../storage/image-backup/';
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir);
        }

        $this->failedLog = $this->backupDir . '/failed.log';

        $this->httpClient = new Client([
            'timeout' => 15,
            'headers' => [
                'Accept' => 'image/*',
                'User-Agent' => 'Mozilla/5.0'
            ]
        ]);

        $posts = $this->postRepository->query()->get();

        // @phpstan-ignore-next-line
        $failedFirstPass = $this->stage1_attemptDirectDownloads($posts);

        if (empty($failedFirstPass)) {
            $this->info("All downloads succeeded or were skipped. Backup process complete.");
            return;
        }

        $archiveUrlsToDownload = $this->stage2_findUrlsInArchive($failedFirstPass);

        if (empty($archiveUrlsToDownload)) {
            $this->info("No images found in archive.org. Backup process complete.");
            return;
        }

        $this->stage3_downloadFromArchive($archiveUrlsToDownload);

        $this->info("Backup process complete.");
    }

    /**
     * @param Collection<int, Post> $posts
     * @return array<int, array{url: string, filepath: string, host: string}>
     */
    private function stage1_attemptDirectDownloads(Collection $posts): array
    {
        $this->info("---" . "Stage 1: Attempting direct downloads" . "---");
        $failedFirstPass = [];

        $failedUrls = file_exists($this->failedLog) ? file($this->failedLog, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES) : [];
        assert(is_array($failedUrls));

        $requests = function () use ($posts, $failedUrls) {
            /** @var Post $post */
            foreach ($posts as $post) {
                if (!$post instanceof CommentPost) {
                    continue;
                }

                if (!preg_match_all('/<IMG.+?src="(https?:\/\/.+?)">/si', $post->getParsedContentAttribute(), $matches)) {
                    continue;
                }

                foreach ($matches[1] as $url) {
                    if (str_contains($url, 'postimg.org')) {
                        $url = str_replace('postimg.org', 'postimg.cc', $url);
                    }

                    if (in_array($url, $failedUrls)) {
                        continue;
                    }

                    $host = parse_url($url, PHP_URL_HOST);
                    assert(is_string($host));

                    $path = parse_url($url, PHP_URL_PATH);
                    assert(is_string($path));

                    if (empty(pathinfo($path, PATHINFO_BASENAME))) {
                        continue;
                    }

                    $filepath = $this->backupDir . $host . '/' . ltrim($path, '/');

                    if (file_exists($filepath)) {
                        continue;
                    }

                    yield ['url' => $url, 'filepath' => $filepath, 'host' => $host] => new Request('GET', $url);
                }
            }
        };

        $pool = new Pool($this->httpClient, $requests(), [
            'concurrency' => 50,
            'fulfilled' => function (ResponseInterface $response, $index): void {
                assert(is_array($index) && isset($index['url']) && isset($index['filepath']));
                assert(is_string($index['url']) && is_string($index['filepath']));
                try {
                    $this->saveImage($response, $index['url'], $index['filepath']);
                } catch (RuntimeException $e) {
                    $this->logFailure($index['url'], 'invalid content');
                }
            },
            'rejected' => function (\Throwable $e, $index) use (&$failedFirstPass): void {
                assert(is_array($index) && isset($index['url']));
                assert(is_string($index['url']));
                $failedFirstPass[] = $index;
                $this->info("Direct download failed, will retry via archive.org: " . $index['url']);
            },
        ]);

        $pool->promise()->wait();

        return $failedFirstPass;
    }

    /**
     * @param array<int, array{url: string, filepath: string, host: string}> $failedFirstPass
     * @return array<int, array{archive_url: string, original_url: string, filepath: string}>
     */
    private function stage2_findUrlsInArchive(array $failedFirstPass): array
    {
        $this->info("---" . "Stage 2: Looking up " . count($failedFirstPass) . " failed URLs in archive.org" . "---");
        $archiveUrlsToDownload = [];

        $requests = function () use ($failedFirstPass) {
            foreach ($failedFirstPass as $failure) {
                assert(isset($failure['host']) && is_string($failure['host']));
                if ($this->isBrokenHost($failure['host'])) {
                    $waybackApiUrl = 'https://archive.org/wayback/available?url=' . urlencode($failure['url']);
                    yield $failure => new Request('GET', $waybackApiUrl);
                }
            }
        };

        $pool = new Pool($this->httpClient, $requests(), [
            'concurrency' => 5,
            'fulfilled' => function (ResponseInterface $response, $index) use (&$archiveUrlsToDownload): void {
                assert(is_array($index) && isset($index['url']) && is_string($index['url']));
                $originalUrl = $index['url'];
                try {
                    $json = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
                    assert(is_array($json));
                    assert(isset($json['archived_snapshots']) && is_array($json['archived_snapshots']));

                    $finalUrl = null;
                    if (isset($json['archived_snapshots']['closest']) && is_array($json['archived_snapshots']['closest'])) {
                        $finalUrl = $json['archived_snapshots']['closest']['url'] ?? null;
                    }

                    if (is_string($finalUrl) && !empty($finalUrl)) {
                        assert(isset($index['filepath']) && is_string($index['filepath']));
                        $archiveUrlsToDownload[] = [
                            'archive_url' => $finalUrl,
                            'original_url' => $originalUrl,
                            'filepath' => $index['filepath']
                        ];
                    } else {
                        $this->logFailure($originalUrl, 'not found in archive');
                    }
                } catch (Exception $e) {
                    $this->logFailure($originalUrl, 'archive.org lookup error');
                }
            },
            'rejected' => function (\Throwable $e, $index): void {
                assert(is_array($index) && isset($index['url']) && is_string($index['url']));
                $this->logFailure($index['url'], 'archive.org API error');
            },
        ]);

        $pool->promise()->wait();

        return $archiveUrlsToDownload;
    }

    /**
     * @param array<int, array{archive_url: string, original_url: string, filepath: string}> $archiveUrlsToDownload
     */
    private function stage3_downloadFromArchive(array $archiveUrlsToDownload): void
    {
        $this->info("---" . "Stage 3: Downloading " . count($archiveUrlsToDownload) . " images found in archive.org" . "---");

        $requests = function () use ($archiveUrlsToDownload) {
            foreach ($archiveUrlsToDownload as $item) {
                yield $item => new Request('GET', $item['archive_url']);
            }
        };

        $pool = new Pool($this->httpClient, $requests(), [
            'concurrency' => 20,
            'fulfilled' => function (ResponseInterface $response, $index): void {
                assert(is_array($index) && isset($index['original_url']) && isset($index['filepath']));
                assert(is_string($index['original_url']) && is_string($index['filepath']));
                try {
                    $this->saveImage($response, $index['original_url'], $index['filepath']);
                } catch (RuntimeException $e) {
                    $this->logFailure($index['original_url'], 'invalid archive content');
                }
            },
            'rejected' => function (\Throwable $e, $index): void {
                assert(is_array($index) && isset($index['original_url']));
                assert(is_string($index['original_url']));
                $this->logFailure($index['original_url'], 'archive download error');
            },
        ]);

        $pool->promise()->wait();
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

    private function logFailure(string $url, string $reason): void
    {
        file_put_contents($this->failedLog, $url . "\n", FILE_APPEND);
        $this->error("Failed ($reason): $url");
    }

    private function isBrokenHost(string $host): bool
    {
        return array_any($this->brokenHosts, fn($brokenHost) => str_ends_with($host, $brokenHost));
    }
}
