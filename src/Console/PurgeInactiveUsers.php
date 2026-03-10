<?php

namespace App\Console;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\User\Event\Deleting;
use Flarum\User\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;

class PurgeInactiveUsers extends Command
{
    private const int INACTIVE_YEARS = 10;
    private const int UNCONFIRMED_DAYS = 7;

    protected $signature = 'app:purge-inactive-users';
    protected $description = 'Delete inactive and unconfirmed user accounts, excluding admins and moderators.';

    public function handle(Dispatcher $events): void
    {
        /** @var User $actor */
        $actor = User::query()
            ->whereHas(
                'groups',
                fn (Builder $query) => $query->where('id', Group::ADMINISTRATOR_ID)
            )
            ->firstOrFail();

        $inactiveCutoff = Carbon::now()->subYears(self::INACTIVE_YEARS);
        $unconfirmedCutoff = Carbon::now()->subDays(self::UNCONFIRMED_DAYS);

        User::query()
            ->where(function (Builder $query) use ($inactiveCutoff, $unconfirmedCutoff) {
                $query->where('last_seen_at', '<=', $inactiveCutoff)
                    ->orWhere(function (Builder $query) use ($unconfirmedCutoff) {
                        $query->where('is_email_confirmed', false)
                            ->where('joined_at', '<=', $unconfirmedCutoff);
                    });
            })
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
