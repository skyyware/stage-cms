<?php
declare(strict_types=1);

namespace StageCms\Content;

final readonly class Field
{
    public function __construct(
        public string $key,
        public string $label,
        public string $group = 'Content',
        public bool $multiline = false,
        public int $limit = 10000,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,119}$/D', $key) || trim($label) === '' || $limit < 1 || $limit > 200000) {
            throw new \InvalidArgumentException('Invalid content field definition.');
        }
    }
}
