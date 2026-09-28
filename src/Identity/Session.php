<?php
declare(strict_types=1);

namespace StageCms\Identity;

use Stage\Security\Caller;

final readonly class Session
{
    public function __construct(public string $secret, public string $csrf, public bool $authenticated, public int $expires) {}

    public function caller(): Caller
    {
        return $this->authenticated ? new Caller('owner', Identity::PERMISSIONS) : new Caller();
    }
}
