<?php

declare(strict_types=1);

namespace SwagUcp\Service;

/**
 * Authorization result value object.
 */
readonly class AuthorizationResult
{
    private function __construct(
        public bool $allowed,
        public string $reason,
        public ?string $agentDomain = null,
    ) {
    }

    public static function allowed(string $reason, ?string $agentDomain = null): self
    {
        return new self(true, $reason, $agentDomain);
    }

    public static function denied(string $reason): self
    {
        return new self(false, $reason);
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
