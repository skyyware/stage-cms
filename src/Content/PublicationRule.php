<?php
declare(strict_types=1);

namespace StageCms\Content;

interface PublicationRule
{
    public function validate(PublicationCandidate $candidate): void;
}
