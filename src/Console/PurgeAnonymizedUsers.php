<?php

namespace App\Console;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\User\Event\Deleting;
use Flarum\User\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;

class PurgeAnonymizedUsers extends Command
{
    private const int RETENTION_DAYS = 30;

    protected $signature = 'app:purge-anonymized-users';
    protected $description = 'Delete anonymized user records after a retention period.';

    public function handle(Dispatcher $events): void
    {
        /** @var User $actor */
        $actor = User::query()
            ->whereHas(
                'groups',
                fn (Builder $query) => $query->where('id', Group::ADMINISTRATOR_ID)
            )
            ->firstOrFail();

        User::query()
            ->where('anonymized', true)
            ->where('joined_at', '<=', Carbon::now()->subDays(self::RETENTION_DAYS))
            ->eachById(function (User $user) use ($events, $actor) {
                $events->dispatch(new Deleting($user, $actor, []));
                $user->delete();
            });
    }
}
