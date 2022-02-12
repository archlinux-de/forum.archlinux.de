<?php

namespace App\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Post\CommentPost;
use Flarum\Post\PostRepository;

class FixBBCode extends AbstractCommand
{
    public function __construct(private PostRepository $postRepository)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('app:fix-bbcode');
    }

    protected function fire(): void
    {
        $this->fixUrlWithinImg();
        $this->fixUrlWithinUrl();
        $this->fixUrl();
    }

    private function fixUrlWithinImg(): void
    {
        /** @var CommentPost $post */
        foreach (
            $this->postRepository->query()->where('type', '=', 'comment')->where(
                'content',
                'LIKE',
                '%<s>[IMG]</s>[url]%'
            )->get() as $post
        ) {
            assert($post instanceof CommentPost);
            $content = $post->content;
            assert(is_string($content));
            $content = preg_replace(
                '/\[IMG\]\[url\]([^\[\]]+?)\[\/url\]\[\/IMG\]/i',
                '[IMG]$1[/IMG]',
                $content
            );
            assert(is_string($content));
            $post->setContentAttribute($content);
            $post->save();

            echo $post->created_at->format('Y'), ' ', $post->id, "\n";
        }
    }

    private function fixUrlWithinUrl(): void
    {
        /** @var CommentPost $post */
        foreach (
            $this->postRepository->query()->where('type', '=', 'comment')->where(
                'content',
                'LIKE',
                '%<s>[URL]</s>[url]%'
            )->get() as $post
        ) {
            assert($post instanceof CommentPost);
            $content = $post->content;
            assert(is_string($content));
            $content = preg_replace(
                '/\[URL\]\[url\]([^\[\]]+?)\[\/url\]\[\/URL\]/i',
                '[URL]$1[/URL]',
                $content
            );
            assert(is_string($content));
            $post->setContentAttribute($content);
            $post->save();

            echo $post->created_at->format('Y'), ' ', $post->id, "\n";
        }
    }

    private function fixUrl(): void
    {
        /** @var CommentPost $post */
        foreach (
            $this->postRepository->query()->where('type', '=', 'comment')->where(
                'content',
                'LIKE',
                '%<s>[URL=[url]</s>%'
            )->get() as $post
        ) {
            assert($post instanceof CommentPost);
            $content = $post->content;
            assert(is_string($content));
            $content = preg_replace(
                '/\[URL=\[url\]([^\[\]]+?)\[\/url\]\/?\](.+?)\[\/URL\]/i',
                '[URL=$1]$2[/URL]',
                $content
            );
            assert(is_string($content));
            $content = preg_replace(
                '/\[url=\[url\](.+?)\](.+?)\[\/url\](.+?)\[\/url\]/i',
                '[URL=$1]$2$3[/URL]',
                $content
            );
            assert(is_string($content));
            $content = preg_replace(
                '/\[URL=\[url\](.+?)\[\/url\]\]\[\/URL\]/i',
                '[URL]$1[/URL]',
                $content
            );
            assert(is_string($content));
            $content = preg_replace(
                '/\[url=\[url\](.+?)\](.+?)\[\/url\]\[\/url\]/i',
                '[URL=$1]$2[/URL]',
                $content
            );
            assert(is_string($content));
            $content = preg_replace(
                '/\[url=\[url\](.+?)\](.+?)\[\/url\]/i',
                '[URL=$1]$2[/URL]',
                $content
            );
            assert(is_string($content));
            $post->setContentAttribute($content);
            $post->save();

            echo $post->created_at->format('Y'), ' ', $post->id, "\n";
        }
    }
}
