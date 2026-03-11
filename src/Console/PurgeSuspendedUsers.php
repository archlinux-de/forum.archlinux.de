<?php

namespace App\Console;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\User\Event\Deleting;
use Flarum\User\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;

class PurgeSuspendedUsers extends Command
{
    private const int SUSPENDED_YEARS = 3;

    protected $signature = 'app:purge-suspended-users';
    protected $description = 'Delete users who have been suspended for more than 3 years.';

    public function handle(Dispatcher $events): void
    {
        /** @var User $actor */
        $actor = User::query()
            ->whereHas(
                'groups',
                fn (Builder $query) => $query->where('id', Group::ADMINISTRATOR_ID)
            )
            ->firstOrFail();

        $cutoff = Carbon::now()->subYears(self::SUSPENDED_YEARS);

        User::query()
            ->where('suspended_until', '>', Carbon::now())
            ->where('last_seen_at', '<', $cutoff)
            ->whereDoesntHave(
                'groups',
                fn (Builder $query) => $query->whereIn('id', [Group::ADMINISTRATOR_ID, Group::MODERATOR_ID])
            )
            ->eachById(function (User $user) use ($events, $actor) {
                $events->dispatch(new Deleting($user, $actor, []));
                $user->delete();
            });
    }
}
