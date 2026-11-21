<?php

/*
 * This file is part of Flarum.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

use App\Console\EnableExtensions;
use App\Console\PurgeAnonymizedUsers;
use App\Console\PurgeInactiveUsers;
use App\Console\PruneHiddenContent;
use App\Console\PurgeSuspendedUsers;
use App\Middleware\ContentSecurityPolicy;
use App\Middleware\NoCacheHeader;
use App\ServiceProvider\ErrorLogProvider;
use App\ServiceProvider\LocalEmojiProvider;
use App\ServiceProvider\SessionServiceProvider;
use Flarum\Extend;
use Flarum\Gdpr\Console\DailySchedule;
use Illuminate\Console\Scheduling\Event;
use s9e\TextFormatter\Configurator;
use s9e\TextFormatter\Configurator\Items\Tag;

return [
    (new Extend\Console())
        ->command(EnableExtensions::class)
        ->command(PurgeAnonymizedUsers::class)
        ->schedule(PurgeAnonymizedUsers::class, DailySchedule::class),
    (new Extend\Console())
        ->command(PurgeInactiveUsers::class)
        ->schedule(PurgeInactiveUsers::class, function (Event $event): void {
            $event->daily();
        }),
    (new Extend\Console())
        ->command(PruneHiddenContent::class)
        ->schedule(PruneHiddenContent::class, function (Event $event): void {
            $event->daily();
        }),
    (new Extend\Console())
        ->command(PurgeSuspendedUsers::class)
        ->schedule(PurgeSuspendedUsers::class, function (Event $event): void {
            $event->daily();
        }),
    (new Extend\ServiceProvider())->register(ErrorLogProvider::class),
    (new Extend\ServiceProvider())->register(LocalEmojiProvider::class),
    (new Extend\ServiceProvider())->register(SessionServiceProvider::class),
    (new Extend\Middleware('forum'))->add(ContentSecurityPolicy::class),
    (new Extend\Middleware('forum'))->add(NoCacheHeader::class),
    (new Extend\Formatter())
        ->configure(function (Configurator $config) {
            // Disable highlight.js
            // https://github.com/s9e/TextFormatter/blob/master/src/Plugins/BBCodes/Configurator/repository.xml#L50-L73
            /** @var Tag $codeTag */
            $codeTag = $config->tags['CODE'];
            $codeTag->setTemplate(
                '<pre><code>'
                . '<xsl:if test="@lang">'
                . '<xsl:attribute name="class">language-<xsl:value-of select="@lang"/></xsl:attribute>'
                . '</xsl:if>'
                . '<xsl:apply-templates /></code></pre>'
            );
        }),
    (new FoF\Sitemap\Extend\Sitemap())->forceCached(),
    (new FoF\Upload\Extend\Adapters())
        ->force('local'),
];
