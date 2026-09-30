<?php
declare(strict_types=1);

namespace StageCms;

use RuntimeException;

final class Failure extends RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly int $status, public readonly string $kind, string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
