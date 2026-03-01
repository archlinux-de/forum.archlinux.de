<?php

namespace App\Console;

use Carbon\Carbon;
use Flarum\Console\AbstractCommand;
use Flarum\Post\CommentPost;
use FoF\Upload\File;
use Ramsey\Uuid\Uuid;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Input\InputOption;

class ImportImages extends AbstractCommand
{
    /** @var list<string> sha1 hashes of known placeholder/error images served with HTTP 200 */
    private const PLACEHOLDER_HASHES = [
        '5af0b5eac2f726754f7423d280c271b6980ae042', // imagevenue.com 404 placeholder (150x150)
        '85c76fb58166cf4de3275b6c73773b974ad2b94e', // pic-upload.de 404 placeholder
        '38ca219048e780e37af31d1348c441dd5fce26a6', // bilderkiste.org 1x1 transparent pixel
        '20002faf28adfd94ca98cf6ced46f14334b53684', // imgur.com "image no longer available" placeholder
        'f4ce39693c3342011c11c4b53d7b13119ed2bb3c', // bilderload.com "404 - Bild nicht gefunden" placeholder
        '4dcb57651a75abfd07fb36c70c6c5108c49bdb34', // ebayimg.com "image not available" placeholder
        '54f42faf8543d7f31fc3983af9b2f9da3b2dbb4c', // imgby.com "Visit imgby.com" placeholder
        '2f14306594f10d7a085618d68492c713cd7795f3', // bilder-hosting.de "nicht mehr verfügbar" placeholder
        '1b9747adc89e8198cd9f1d0a437f30a2779e6f1d', // workupload.com 404 placeholder
        'da6a2e96f673b1efca7b407f8adc269d91ba15e7', // t-cdn.net spam image
    ];

    private string $backupDir;

    protected function configure(): void
    {
        $this->setName('app:import-images')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be done without making changes');
    }

    protected function fire(): void
    {
        ini_set('memory_limit', '4G');

        $this->backupDir = __DIR__ . '/../../storage/image-backup/';
        $dryRun = (bool) $this->input->getOption('dry-run');

        $assetsDir = __DIR__ . '/../../public/assets/files/';

        // Step 1: Build backup file index
        $this->info('Building backup file index...');
        $backupIndex = $this->buildBackupIndex();
        $this->info(sprintf('Found %d backed up images.', count($backupIndex)));

        // Step 2: Find posts with matching external images
        $this->info('Scanning posts for external images...');
        $imageMap = $this->buildImageMap($backupIndex);
        $totalPosts = $this->countTotalPosts($imageMap);
        $this->info(sprintf(
            'Found %d unique images referenced in %d posts.',
            count($imageMap),
            $totalPosts,
        ));

        // Step 3: Import each image
        $imported = 0;
        $skipped = 0;
        $recycled = 0;
        $failed = 0;
        $postsUpdated = 0;

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new \RuntimeException('finfo_open failed');
        }

        foreach ($imageMap as $normalizedUrl => $entry) {
            // Idempotency: skip if already imported
            $existing = File::query()->where('remote_id', $normalizedUrl)->first();
            if ($existing) {
                $skipped++;
                continue;
            }

            $filepath = $entry['filepath'];

            if (!file_exists($filepath)) {
                $this->error("Backup file missing: $filepath");
                $failed++;
                continue;
            }

            $sha1 = sha1_file($filepath);
            if ($sha1 === false) {
                $this->error("Failed to hash file: $filepath");
                $failed++;
                continue;
            }
            if (in_array($sha1, self::PLACEHOLDER_HASHES, true)) {
                $this->info("Skipping placeholder image: $normalizedUrl");
                $recycled++;
                continue;
            }

            $mimeType = (string) finfo_file($finfo, $filepath);
            if (!str_starts_with($mimeType, 'image/')) {
                $this->error("Not an image ($mimeType): $filepath");
                $failed++;
                continue;
            }

            $fileSize = filesize($filepath);
            if ($fileSize === false) {
                $this->error("Failed to get file size: $filepath");
                $failed++;
                continue;
            }
            $uuid = Uuid::uuid4()->toString();
            $baseName = $this->fixExtension(basename($filepath), $mimeType);

            // Find the earliest post referencing this image
            /** @var CommentPost|null $earliestPost */
            $earliestPost = CommentPost::query()
                ->whereIn('id', $entry['postIds'])
                ->orderBy('created_at')
                ->first();
            $actorId = $earliestPost->user_id ?? null;

            // Use file mtime, but if it's newer than the earliest post, use the post date instead
            $mtime = filemtime($filepath);
            $createdAt = $mtime ? Carbon::createFromTimestamp($mtime) : Carbon::now();

            // postimg.cc recycles expired image URLs with unrelated content.
            // Detect this by checking if the file mtime is far newer than the post.
            if ($earliestPost && str_contains($normalizedUrl, 'postimg')) {
                $postDate = Carbon::instance($earliestPost->created_at);
                if ($createdAt->isAfter($postDate->copy()->addYear())) {
                    $this->error(sprintf(
                        'Skipping likely recycled postimg image: %s (file: %s, post: %s)',
                        $normalizedUrl,
                        $createdAt->toDateString(),
                        $postDate->toDateString(),
                    ));
                    $recycled++;
                    continue;
                }
            }

            if ($earliestPost && $createdAt->isAfter($earliestPost->created_at)) {
                $createdAt = Carbon::instance($earliestPost->created_at);
            }

            $relativePath = $this->buildPath($createdAt, $sha1, $baseName);

            if ($dryRun) {
                $this->info(sprintf(
                    '[DRY-RUN] Would import: %s -> %s (in %d posts)',
                    $normalizedUrl,
                    $relativePath,
                    count($entry['postIds']),
                ));
                $imported++;
                continue;
            }

            // Copy file
            $datePart = $createdAt->format('Y-m-d');
            $targetDir = $assetsDir . $datePart;
            if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
                $this->error("Failed to create directory: $targetDir");
                $failed++;
                continue;
            }

            $targetPath = $assetsDir . $relativePath;
            if (!copy($filepath, $targetPath)) {
                $this->error("Failed to copy file to: $targetPath");
                $failed++;
                continue;
            }

            // Create fof_upload_files record
            $file = new File();
            $file->forceFill([
                'uuid' => $uuid,
                'base_name' => $baseName,
                'path' => $relativePath,
                'url' => 'https://forum.archlinux.de/assets/files/' . $relativePath,
                'type' => $mimeType,
                'size' => $fileSize,
                'actor_id' => $actorId,
                'upload_method' => 'local',
                'remote_id' => $normalizedUrl,
                'created_at' => $createdAt,
            ]);
            $file->saveQuietly();
            // Set tag directly to bypass the setTagAttribute mutator
            $file->newQuery()->where('id', $file->id)->update(['tag' => 'image-preview']);

            // Replace <IMG> tags in all posts referencing this URL
            $replacement = '<UPL-IMAGE-PREVIEW uuid="' . $uuid . '">'
                . '<s>[upl-image-preview uuid=' . $uuid . ']</s>'
                . '</UPL-IMAGE-PREVIEW>';

            $postIdsUpdated = [];
            foreach ($entry['urlsInContent'] as $urlInContent) {
                $pattern = '/<IMG\b[^>]*\bsrc="'
                    . preg_quote($urlInContent, '/')
                    . '"[^>]*>.*?<\/IMG>/s';

                foreach ($entry['postIds'] as $postId) {
                    /** @var CommentPost|null $post */
                    $post = CommentPost::query()->find($postId);
                    if (!$post) {
                        continue;
                    }

                    $content = $post->getParsedContentAttribute();
                    $newContent = preg_replace($pattern, $replacement, (string) $content);
                    if ($newContent !== null && $newContent !== $content) {
                        // Strip <URL> wrapper linking to the image hosting service
                        $domainPattern = $this->getUnwrapDomainPattern(
                            (string) parse_url($urlInContent, PHP_URL_HOST),
                        );
                        $unwrapPattern = '/<URL\b[^>]*\burl="[^"]*'
                            . $domainPattern
                            . '[^"]*"[^>]*><s>[^<]*<\/s>'
                            . preg_quote($replacement, '/')
                            . '(?:(?!<URL\b).)*?<\/URL>/s';
                        $newContent = preg_replace(
                            $unwrapPattern,
                            $replacement,
                            $newContent,
                        ) ?? $newContent;
                        $post->setParsedContentAttribute($newContent);
                        $post->saveQuietly();
                        $postIdsUpdated[$postId] = true;
                    }
                }
            }

            // Link file to posts
            /** @var \Illuminate\Database\Eloquent\Relations\BelongsToMany $postsRelation */
            $postsRelation = $file->posts();
            $postsRelation->syncWithoutDetaching(array_keys($postIdsUpdated));

            $imported++;
            $postsUpdated += count($postIdsUpdated);
            $this->info(sprintf(
                'Imported: %s -> %s (%d posts updated)',
                $normalizedUrl,
                $relativePath,
                count($postIdsUpdated),
            ));
        }

        $this->info('');
        $this->info('--- Summary ---');
        $this->info(sprintf('Imported: %d', $imported));
        $this->info(sprintf('Posts updated: %d', $postsUpdated));
        $this->info(sprintf('Skipped (already imported): %d', $skipped));
        $this->info(sprintf('Skipped (recycled by host): %d', $recycled));
        $this->info(sprintf('Failed: %d', $failed));
    }

    /**
     * Build a deterministic file path mimicking fof/upload's {date}/{timestamp}-{micro}-{basename} format.
     * The microsecond part is derived from the file's sha1 hash for determinism.
     */
    private function buildPath(Carbon $createdAt, string $sha1, string $baseName): string
    {
        $datePart = $createdAt->format('Y-m-d');
        $timestamp = $createdAt->timestamp;
        $micro = str_pad((string) (hexdec(substr($sha1, 0, 5)) % 1000000), 6, '0', STR_PAD_LEFT);
        return $datePart . '/' . $timestamp . '-' . $micro . '-' . $baseName;
    }

    /**
     * Build an index mapping normalized URLs to backup file paths.
     *
     * @return array<string, string> normalized URL => filepath
     */
    private function buildBackupIndex(): array
    {
        $index = [];

        if (!is_dir($this->backupDir)) {
            return $index;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->backupDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            assert($file instanceof \SplFileInfo);

            if (!$file->isFile() || $file->getFilename() === 'failed.log') {
                continue;
            }

            $realPath = $file->getPathname();
            $relativePath = substr($realPath, strlen($this->backupDir));

            // Reconstruct URL: first path component is host, rest is path
            $slashPos = strpos($relativePath, '/');
            if ($slashPos === false) {
                continue;
            }

            $host = substr($relativePath, 0, $slashPos);
            $path = substr($relativePath, $slashPos);

            $url = 'https://' . $host . $path;
            $index[$url] = $realPath;
            $index[$url . '/'] = $realPath;

            // Also register http:// variant since some posts use http
            $index['http://' . $host . $path] = $realPath;
            $index['http://' . $host . $path . '/'] = $realPath;

            // Also register postimg.cc variants for postimg.org lookups
            if (str_contains($host, 'postimg.cc')) {
                $orgHost = str_replace('postimg.cc', 'postimg.org', $host);
                $index['https://' . $orgHost . $path] = $realPath;
                $index['http://' . $orgHost . $path] = $realPath;
            }
        }

        return $index;
    }

    /**
     * Scan all posts and build a map of normalized URL => {filepath, postIds, urlsInContent}.
     *
     * @param array<string, string> $backupIndex
     * @return array<string, array{filepath: string, postIds: list<int>, urlsInContent: list<string>}>
     */
    private function buildImageMap(array $backupIndex): array
    {
        /** @var array<string, array{filepath: string, postIds: list<int>, urlsInContent: list<string>}> $imageMap */
        $imageMap = [];

        CommentPost::query()
            ->select(['id', 'content'])
            ->chunkById(1000, function (
                \Illuminate\Database\Eloquent\Collection $posts,
            ) use (
                $backupIndex,
                &$imageMap,
            ): void {
                /** @var CommentPost $post */
                foreach ($posts as $post) {
                    $content = (string) $post->getParsedContentAttribute();
                    $imgPattern = '/<IMG\b[^>]*\bsrc="(https?:\/\/[^"]+)"[^>]*>/si';
                    if (!preg_match_all($imgPattern, $content, $matches)) {
                        continue;
                    }

                    foreach ($matches[1] as $urlInContent) {
                        // Normalize to https and postimg.cc for deduplication
                        $normalizedUrl = $urlInContent;
                        if (str_starts_with($normalizedUrl, 'http://')) {
                            $normalizedUrl = 'https://' . substr($normalizedUrl, 7);
                        }
                        if (str_contains($normalizedUrl, 'postimg.org')) {
                            $normalizedUrl = str_replace('postimg.org', 'postimg.cc', $normalizedUrl);
                        }

                        if (!isset($backupIndex[$normalizedUrl])) {
                            // Try without port (backup saves host without port)
                            $urlWithoutPort = (string) preg_replace('#^(https?://[^/:]+):\d+#', '$1', $normalizedUrl);
                            // Try without query string (e.g. imgur ?1 cache busters)
                            $urlWithoutQuery = strtok($normalizedUrl, '?');
                            $urlWithoutBoth = strtok($urlWithoutPort, '?');
                            if ($urlWithoutPort !== $normalizedUrl && isset($backupIndex[$urlWithoutPort])) {
                                $normalizedUrl = $urlWithoutPort;
                            } elseif ($urlWithoutQuery !== false && isset($backupIndex[$urlWithoutQuery])) {
                                $normalizedUrl = $urlWithoutQuery;
                            } elseif ($urlWithoutBoth !== false && isset($backupIndex[$urlWithoutBoth])) {
                                $normalizedUrl = $urlWithoutBoth;
                            } else {
                                continue;
                            }
                        }

                        if (!isset($imageMap[$normalizedUrl])) {
                            $imageMap[$normalizedUrl] = [
                                'filepath' => $backupIndex[$normalizedUrl],
                                'postIds' => [],
                                'urlsInContent' => [],
                            ];
                        }

                        if (!in_array($post->id, $imageMap[$normalizedUrl]['postIds'])) {
                            $imageMap[$normalizedUrl]['postIds'][] = $post->id;
                        }

                        if (!in_array($urlInContent, $imageMap[$normalizedUrl]['urlsInContent'])) {
                            $imageMap[$normalizedUrl]['urlsInContent'][] = $urlInContent;
                        }
                    }
                }
            });

        return $imageMap;
    }

    /**
     * @param array<string, array{filepath: string, postIds: list<int>, urlsInContent: list<string>}> $imageMap
     */
    private function countTotalPosts(array $imageMap): int
    {
        $postIds = [];
        foreach ($imageMap as $entry) {
            foreach ($entry['postIds'] as $postId) {
                $postIds[$postId] = true;
            }
        }
        return count($postIds);
    }

    /**
     * Build a regex pattern to match URL wrapper hosts for the given image host.
     *
     * For known image hosting services, matches all domains belonging to the same service
     * (e.g. i.ibb.co → also matches ibb.co, imgbb.com gallery page URLs).
     * For other hosts, falls back to exact host matching to avoid stripping intentional
     * links (e.g. xkcd.com, wiki.archlinux.de).
     */
    private function getUnwrapDomainPattern(string $host): string
    {
        $baseDomain = $this->baseDomain($host);

        /** @var list<list<string>> $services */
        $services = [
            ['abload.de'],
            ['directupload.net'],
            ['ibb.co', 'imgbb.com'],
            ['imageshack.us'],
            ['imgbox.com'],
            ['imgur.com'],
            ['pic-upload.de'],
            ['picr.de'],
            ['postimg.cc', 'postimg.org', 'postimage.org', 'postimages.org'],
        ];

        foreach ($services as $domains) {
            if (in_array($baseDomain, $domains, true)) {
                $escaped = array_map(static fn(string $d): string => preg_quote($d, '/'), $domains);
                return '(?:' . implode('|', $escaped) . ')';
            }
        }

        return preg_quote($host, '/');
    }

    private function baseDomain(string $host): string
    {
        $parts = explode('.', $host);
        return implode('.', array_slice($parts, -2));
    }

    /**
     * Fix the file extension to match the actual MIME type.
     * Image hosts often serve JPEG content with .png or .gif extensions.
     */
    private function fixExtension(string $baseName, string $mimeType): string
    {
        $correctExt = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/svg+xml' => 'svg',
            default => null,
        };

        if ($correctExt === null) {
            return $baseName;
        }

        $result = (string) preg_replace('/\.(jpe?g|png|gif|svg)$/i', '.' . $correctExt, $baseName);
        if ($result === $baseName && !str_ends_with(strtolower($baseName), '.' . $correctExt)) {
            return $baseName . '.' . $correctExt;
        }
        return $result;
    }
}
