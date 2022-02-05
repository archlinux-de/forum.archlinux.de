<?php

namespace App\Frontend;

use Flarum\Frontend\Document;

class ClickImage
{
    public const IMG_TEMPLATE =
        '<click-img src="{@src}" title="{@title}" alt="{@alt}">'
        . '<xsl:copy-of select="@height"/><xsl:copy-of select="@width"/>'
        . '</click-img>';

    public function __invoke(Document $document): void
    {
        $document->foot[] = '<script async src="/click-image.js?v=0.2"></script>';
    }
}
