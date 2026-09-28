<?php
declare(strict_types=1);

namespace StageCms;

use RuntimeException;

final class Failure extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $kind, string $message)
    {
        parent::__construct($message);
    }
}
