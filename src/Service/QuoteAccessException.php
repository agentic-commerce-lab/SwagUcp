<?php

declare(strict_types=1);

namespace SwagUcp\Service;

use Symfony\Component\HttpFoundation\Response;

/**
 * Machine-readable access errors for the quote capability. Codes and status
 * codes are part of the published capability contract (quote.openapi.json).
 */
class QuoteAccessException extends \RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        private readonly int $statusCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function buyerNotFound(): self
    {
        return new self('buyer_not_found', Response::HTTP_NOT_FOUND, 'Buyer claim does not resolve to a customer');
    }

    public static function agentNotAuthorized(): self
    {
        return new self('agent_not_authorized', Response::HTTP_FORBIDDEN, 'Agent platform is not authorized for this customer');
    }

    public static function quoteNotEnabledForBuyer(): self
    {
        return new self('quote_not_enabled_for_buyer', Response::HTTP_FORBIDDEN, 'Quote Management is not enabled for this customer');
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
