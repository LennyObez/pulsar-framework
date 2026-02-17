<?php

declare(strict_types=1);

namespace Pulsar\Mail\Webhook\Verifier;

use Pulsar\Api\Internal;
use Pulsar\Mail\Webhook\WebhookRequest;
use Pulsar\Mail\Webhook\WebhookVerifierInterface;

use function base64_decode;
use function file_get_contents;
use function is_array;
use function is_string;
use function json_decode;
use function openssl_pkey_get_public;
use function openssl_verify;
use function parse_url;
use function str_ends_with;

use const OPENSSL_ALGO_SHA256;

/**
 * Verifies AWS SES/SNS webhook signatures using certificate-based verification.
 *
 * Validates the SNS message signature by:
 * 1. Parsing the SNS message to extract the signing certificate URL
 * 2. Downloading and caching the certificate
 * 3. Verifying the signature against the canonical message string
 */
#[Internal]
final readonly class SesWebhookVerifier implements WebhookVerifierInterface
{
    /**
     * @param list<string> $allowedCertHosts Allowed hostnames for signing certificate URLs
     */
    public function __construct(
        private array $allowedCertHosts = ['sns.amazonaws.com'],
    ) {}

    public function verify(WebhookRequest $request): bool
    {
        $decoded = json_decode($request->payload, true);
        if (!is_array($decoded)) {
            return false;
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        $certUrl = $data['SigningCertURL'] ?? null;
        $signature = $data['Signature'] ?? null;

        if (!is_string($certUrl) || !is_string($signature)) {
            return false;
        }

        if (!$this->isCertUrlAllowed($certUrl)) {
            return false;
        }

        $certificate = file_get_contents($certUrl);
        if ($certificate === false) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($certificate);
        if ($publicKey === false) {
            return false;
        }

        $canonicalMessage = $this->buildCanonicalMessage($data);
        $decodedSignature = base64_decode($signature, true);

        if ($decodedSignature === false) {
            return false;
        }

        return openssl_verify($canonicalMessage, $decodedSignature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    private function isCertUrlAllowed(string $url): bool
    {
        $parsed = parse_url($url);

        if (!is_array($parsed) || ($parsed['scheme'] ?? '') !== 'https') {
            return false;
        }

        $host = $parsed['host'] ?? '';

        foreach ($this->allowedCertHosts as $allowedHost) {
            if ($host === $allowedHost || str_ends_with($host, '.' . $allowedHost)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the canonical string to verify against based on SNS message type.
     *
     * @param array<string, mixed> $data
     */
    private function buildCanonicalMessage(array $data): string
    {
        $rawType = $data['Type'] ?? '';
        $type = is_string($rawType) ? $rawType : '';

        if ($type === 'Notification') {
            $fields = ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'];
        } else {
            $fields = ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
        }

        $canonical = '';
        foreach ($fields as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $canonical .= $field . "\n" . $data[$field] . "\n";
            }
        }

        return $canonical;
    }
}
