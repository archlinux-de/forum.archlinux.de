<?php

namespace App\Console;

use DOMDocument;
use Flarum\Console\AbstractCommand;
use Flarum\Post\CommentPost;
use FoF\Upload\File;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

class ValidatePosts extends AbstractCommand
{
    protected function configure(): void
    {
        $this->setName('app:validate-posts')
            ->setDescription('Validate post content and database consistency')
            ->addOption('shard', null, InputOption::VALUE_REQUIRED, 'Shard index/total, e.g. 0/4')
            ->addOption('metadata-only', null, InputOption::VALUE_NONE, 'Only run metadata checks');
    }

    protected function fire(): void
    {
        /** @var ?string $shard */
        $shard = $this->input->getOption('shard');
        $shardIndex = 0;
        $shardTotal = 1;

        $metadataOnly = (bool) $this->input->getOption('metadata-only');

        if ($metadataOnly) {
            $this->validateMetadata();
            return;
        }

        if ($shard !== null) {
            [$shardIndex, $shardTotal] = array_map('intval', explode('/', $shard));
            $this->info(sprintf('Validating shard %d/%d...', $shardIndex, $shardTotal));
        } else {
            $this->info('Validating posts...');
        }

        $this->validateContent($shardIndex, $shardTotal);
    }

    private function validateContent(int $shardIndex, int $shardTotal): void
    {
        $total = 0;
        $xmlErrors = 0;
        $renderErrors = 0;
        $orphanedUuids = 0;

        // Pre-load all known UUIDs for orphan check
        $knownUuids = File::query()->pluck('uuid')->flip()->all();

        $query = CommentPost::query()
            ->where('type', 'comment')
            ->select(['id', 'content']);

        if ($shardTotal > 1) {
            $query->whereRaw('id % ? = ?', [$shardTotal, $shardIndex]);
        }

        $query->chunkById(1000, function (
            \Illuminate\Database\Eloquent\Collection $posts,
        ) use (
            &$total,
            &$xmlErrors,
            &$renderErrors,
            &$orphanedUuids,
            $knownUuids,
        ): void {
            /** @var CommentPost $post */
            foreach ($posts as $post) {
                $total++;
                $xml = (string) $post->getParsedContentAttribute();

                if ($xml === '') {
                    continue;
                }

                // Check 1: XML well-formedness
                $useErrors = libxml_use_internal_errors(true);
                $dom = new DOMDocument();
                $success = $dom->loadXML($xml);
                $errors = libxml_get_errors();
                libxml_clear_errors();
                libxml_use_internal_errors($useErrors);

                if (!$success || count($errors) > 0) {
                    $xmlErrors++;
                    $errorMsg = count($errors) > 0
                        ? trim($errors[0]->message)
                        : 'unknown error';
                    $this->error(sprintf(
                        'Post %d: XML error: %s',
                        $post->id,
                        $errorMsg,
                    ));
                    continue;
                }

                // Check 2: Render
                try {
                    $post->formatContent();
                } catch (\Throwable $e) {
                    $renderErrors++;
                    $this->error(sprintf(
                        'Post %d: Render error: %s',
                        $post->id,
                        $e->getMessage(),
                    ));
                }

                // Check 3: Orphaned UPL-IMAGE-PREVIEW UUIDs
                if (
                    preg_match_all(
                        '/UPL-IMAGE-PREVIEW[^>]*\buuid="([^"]+)"/',
                        $xml,
                        $uuidMatches,
                    )
                ) {
                    foreach (array_unique($uuidMatches[1]) as $uuid) {
                        if (!isset($knownUuids[$uuid])) {
                            $orphanedUuids++;
                            $this->error(sprintf(
                                'Post %d: Orphaned upload UUID: %s',
                                $post->id,
                                $uuid,
                            ));
                        }
                    }
                }
            }
        });

        $this->info('');
        $this->info('--- Content ---');
        $this->info(sprintf('Posts validated: %d', $total));
        $this->info(sprintf('XML errors: %d', $xmlErrors));
        $this->info(sprintf('Render errors: %d', $renderErrors));
        $this->info(sprintf('Orphaned upload UUIDs: %d', $orphanedUuids));
    }

    private function validateMetadata(): void
    {
        $this->info('');
        $this->info('--- Metadata ---');

        /** @var ConnectionInterface $db */
        $db = resolve(ConnectionInterface::class);

        $checks = [
            'Discussions: wrong comment_count' => '
                SELECT COUNT(*) FROM discussions d
                WHERE comment_count != (
                    SELECT COUNT(*) FROM posts p
                    WHERE p.discussion_id = d.id AND p.type = "comment" AND p.hidden_at IS NULL
                )',
            'Discussions: wrong first_post_id' => '
                SELECT COUNT(*) FROM discussions d
                WHERE first_post_id != (
                    SELECT MIN(id) FROM posts p
                    WHERE p.discussion_id = d.id AND p.type = "comment"
                )',
            'Discussions: wrong last_post_id' => '
                SELECT COUNT(*) FROM discussions d
                WHERE last_post_id != (
                    SELECT MAX(id) FROM posts p
                    WHERE p.discussion_id = d.id AND p.type = "comment" AND p.hidden_at IS NULL
                )',
            'Discussions: wrong last_post_number' => '
                SELECT COUNT(*) FROM discussions d
                WHERE last_post_number != (
                    SELECT MAX(number) FROM posts p
                    WHERE p.discussion_id = d.id AND p.type = "comment" AND p.hidden_at IS NULL
                )',
            'Posts: orphaned discussion_id' => '
                SELECT COUNT(*) FROM posts p
                LEFT JOIN discussions d ON d.id = p.discussion_id
                WHERE d.id IS NULL',
            'Posts: orphaned user_id' => '
                SELECT COUNT(*) FROM posts p
                LEFT JOIN users u ON u.id = p.user_id
                WHERE p.user_id IS NOT NULL AND u.id IS NULL',
            'Posts: edited_at before created_at' => '
                SELECT COUNT(*) FROM posts
                WHERE edited_at IS NOT NULL AND edited_at < created_at',
            'Uploads: file not linked to any post' => '
                SELECT COUNT(*) FROM fof_upload_files f
                LEFT JOIN fof_upload_file_posts fp ON fp.file_id = f.id
                WHERE fp.id IS NULL',
            'Uploads: orphaned file_posts.file_id' => '
                SELECT COUNT(*) FROM fof_upload_file_posts fp
                LEFT JOIN fof_upload_files f ON f.id = fp.file_id
                WHERE f.id IS NULL',
            'Uploads: orphaned file_posts.post_id' => '
                SELECT COUNT(*) FROM fof_upload_file_posts fp
                LEFT JOIN posts p ON p.id = fp.post_id
                WHERE p.id IS NULL',
            'Posts: UPL-IMAGE-PREVIEW without file link' => '
                SELECT COUNT(*) FROM posts p
                WHERE p.type = "comment"
                AND p.content LIKE "%UPL-IMAGE-PREVIEW%"
                AND p.id NOT IN (SELECT post_id FROM fof_upload_file_posts)',
        ];

        foreach ($checks as $label => $sql) {
            /** @var object{cnt: int} $row */
            $row = $db->selectOne("SELECT ($sql) as cnt");
            $count = $row->cnt;
            if ($count > 0) {
                $this->error(sprintf('%s: %d', $label, $count));
            } else {
                $this->info(sprintf('%s: %d', $label, $count));
            }
        }
    }
}
