<?php

declare(strict_types=1);

namespace SwagUcp\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service for sending signed UCP webhooks.
 *
 * Implements the UCP webhook signing protocol using Detached JWS (RFC 7797)
 * with ES256 signatures as specified in the UCP order extension.
 */
class WebhookService
{
    public function __construct(
        private readonly SignatureVerificationService $signatureService,
        private readonly ?HttpClientInterface $httpClient = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Send a signed webhook to a platform.
     *
     * @param string $webhookUrl Platform's webhook URL
     * @param array $payload Webhook payload
     * @param string $salesChannelId Sales channel ID for signing key lookup
     * @param string $eventType Event type (e.g., 'order.shipped', 'order.delivered')
     *
     * @return WebhookResult Result of the webhook call
     */
    public function sendWebhook(
        string $webhookUrl,
        array $payload,
        string $salesChannelId,
        string $eventType
    ): WebhookResult {
        try {
            // Serialize payload
            $body = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            if ($body === false) {
                return new WebhookResult(false, 'Failed to serialize payload');
            }

            // Create signature
            $signature = $this->signatureService->createRequestSignature($body, $salesChannelId);

            // Build headers
            $headers = [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Request-Signature' => $signature,
                'X-UCP-Event' => $eventType,
                'User-Agent' => 'SwagUcp/1.0',
            ];

            // Send webhook
            if ($this->httpClient === null) {
                // Fallback to file_get_contents
                $context = stream_context_create([
                    'http' => [
                        'method' => 'POST',
                        'header' => $this->buildHeaderString($headers),
                        'content' => $body,
                        'timeout' => 30,
                        'ignore_errors' => true,
                    ],
                    'ssl' => [
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                    ],
                ]);

                $response = @file_get_contents($webhookUrl, false, $context);
                $statusCode = $this->extractStatusCode($http_response_header ?? []);
            } else {
                $response = $this->httpClient->request('POST', $webhookUrl, [
                    'headers' => $headers,
                    'body' => $body,
                    'timeout' => 30,
                ]);
                $statusCode = $response->getStatusCode();
                $response = $response->getContent(false);
            }

            // Check response
            if ($statusCode >= 200 && $statusCode < 300) {
                $this->log('info', 'Webhook sent successfully', [
                    'url' => $webhookUrl,
                    'event' => $eventType,
                    'status' => $statusCode,
                ]);

                return new WebhookResult(true, 'Success', $statusCode, $response);
            }

            $this->log('warning', 'Webhook returned non-success status', [
                'url' => $webhookUrl,
                'event' => $eventType,
                'status' => $statusCode,
                'response' => $response,
            ]);

            return new WebhookResult(false, "HTTP {$statusCode}", $statusCode, $response);
        } catch (\Exception $e) {
            $this->log('error', 'Webhook failed with exception', [
                'url' => $webhookUrl,
                'event' => $eventType,
                'error' => $e->getMessage(),
            ]);

            return new WebhookResult(false, $e->getMessage());
        }
    }

    /**
     * Send an order event webhook.
     *
     * @param string $webhookUrl Platform's webhook URL
     * @param string $orderId Order ID
     * @param string $eventType Event type (e.g., 'shipped', 'delivered', 'returned')
     * @param array $eventData Additional event data
     * @param string $salesChannelId Sales channel ID
     */
    public function sendOrderEvent(
        string $webhookUrl,
        string $orderId,
        string $eventType,
        array $eventData,
        string $salesChannelId
    ): WebhookResult {
        $payload = [
            'order_id' => $orderId,
            'event' => $eventType,
            'timestamp' => (new \DateTime())->format('c'),
            'data' => $eventData,
        ];

        return $this->sendWebhook($webhookUrl, $payload, $salesChannelId, "order.{$eventType}");
    }

    /**
     * Build header string for file_get_contents context.
     */
    private function buildHeaderString(array $headers): string
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        return implode("\r\n", $lines);
    }

    /**
     * Extract HTTP status code from response headers.
     */
    private function extractStatusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\d\.\d\s+(\d+)/', $header, $matches)) {
                return (int) $matches[1];
            }
        }

        return 0;
    }

    /**
     * Log a message if logger is available.
     *
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->log($level, '[UCP Webhook] ' . $message, $context);
        }
    }
}
