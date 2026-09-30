<?php
declare(strict_types=1);

namespace StageCms\Presentation;

final readonly class ThemeOption
{
    public function __construct(public string $id, public string $label, public Theme $theme)
    {
        if (!preg_match('/^[a-z][a-z0-9-]{0,59}$/D', $id) || trim($label) === '') {
            throw new \InvalidArgumentException('Invalid theme definition.');
        }
    }
}
