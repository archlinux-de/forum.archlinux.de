<?php

namespace App\Console;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Deleting;
use Flarum\Group\Group;
use Flarum\Post\Command\DeletePost;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;

class PruneHiddenContent extends Command
{
    private const int HIDDEN_DAYS = 30;

    protected $signature = 'app:prune-hidden';
    protected $description = 'Permanently delete hidden content older than 30 days.';

    public function handle(Dispatcher $events, Bus $bus): void
    {
        /** @var User $actor */
        $actor = User::query()
            ->whereHas(
                'groups',
                fn (Builder $query) => $query->where('id', Group::ADMINISTRATOR_ID)
            )
            ->firstOrFail();

        $threshold = Carbon::now()->subDays(self::HIDDEN_DAYS);

        Discussion::query()
            ->whereNotNull('hidden_at')
            ->where('hidden_at', '<', $threshold)
            ->eachById(function (Discussion $discussion) use ($events, $actor) {
                $events->dispatch(new Deleting($discussion, $actor, []));
                $discussion->delete();
            });

        Post::query()
            ->whereNotNull('hidden_at')
            ->where('hidden_at', '<', $threshold)
            ->eachById(fn (Post $post) => $bus->dispatch(new DeletePost($post->id, $actor, [])));
    }
}
