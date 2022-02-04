<?php

/*
 * This file is part of Flarum.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

use App\Console\EnableExtensions;
use App\Frontend\ClickImage;
use App\Middleware\ContentSecurityPolicy;
use App\ServiceProvider\ErrorLogProvider;
use App\ServiceProvider\SessionServiceProvider;
use Flarum\Extend;
use s9e\TextFormatter\Configurator;
use s9e\TextFormatter\Configurator\Items\Tag;

return [
    (new Extend\Console())->command(EnableExtensions::class),
    (new Extend\ServiceProvider())->register(ErrorLogProvider::class),
    (new Extend\ServiceProvider())->register(SessionServiceProvider::class),
    (new Extend\Middleware('forum'))->add(ContentSecurityPolicy::class),
    (new Extend\Formatter())
        ->configure(function (Configurator $config) {
            // Emoticons used to be provided by flarum/emoji
            // https://github.com/flarum/emoji/blob/master/extend.php#L20-L28
            $config->Emoticons->add(':)', '🙂');
            $config->Emoticons->add(':D', '😃');
            $config->Emoticons->add(':P', '😛');
            $config->Emoticons->add(':(', '🙁');
            $config->Emoticons->add(':|', '😐');
            $config->Emoticons->add(';)', '😉');
            $config->Emoticons->add(':\'(', '😢');
            $config->Emoticons->add(':O', '😮');
            $config->Emoticons->add('>:(', '😡');

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

            // Override img tag
            // https://github.com/s9e/TextFormatter/blob/master/src/Plugins/BBCodes/Configurator/repository.xml#L216-L221
            /** @var Tag $imgTag */
            $imgTag = $config->tags['IMG'];
            $imgTag->setTemplate(ClickImage::IMG_TEMPLATE);
        }),
    (new Extend\Frontend('forum'))->content(ClickImage::class)
];
