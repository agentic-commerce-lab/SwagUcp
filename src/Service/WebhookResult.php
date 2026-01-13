<?php

declare(strict_types=1);

namespace SwagUcp\Service;

/**
 * Result of a webhook call.
 */
readonly class WebhookResult
{
    public function __construct(
        public bool $success,
        public string $message,
        public ?int $statusCode = null,
        public ?string $response = null,
    ) {
    }
}
