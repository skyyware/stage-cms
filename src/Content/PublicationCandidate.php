<?php
declare(strict_types=1);

namespace StageCms\Content;

final readonly class PublicationCandidate
{
    public function __construct(public string $pageId, public int $version, public Draft $draft) {}
}
