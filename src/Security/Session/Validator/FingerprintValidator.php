<?php

declare(strict_types=1);

namespace Pulsar\Security\Session\Validator;

use Override;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Security\Crypto\HmacInterface;
use Pulsar\Security\Session\SessionMetadata;
use SensitiveParameter;

use function hash_equals;
use function implode;

/**
 * Validates a browser fingerprint computed from stable request headers.
 *
 * Builds an HMAC-based fingerprint from configured request attributes and
 * compares it against the fingerprint stored in session metadata.
 */
#[Internal]
final readonly class FingerprintValidator implements SessionValidatorInterface
{
    private const array HEADER_MAP = [
        'accept_language' => 'Accept-Language',
        'accept_encoding' => 'Accept-Encoding',
    ];

    /**
     * @param list<string> $attributes
     */
    public function __construct(
        private HmacInterface $hmac,
        #[SensitiveParameter]
        private string $hmacKey,
        private array $attributes = ['accept_language', 'accept_encoding'],
    ) {}

    #[Override]
    public function validate(SessionMetadata $metadata, ServerRequestInterface $request): bool
    {
        if ($metadata->fingerprint === null) {
            return true;
        }

        $currentFingerprint = $this->computeFingerprint($request);

        return hash_equals($metadata->fingerprint, $currentFingerprint);
    }

    #[Override]
    public function getName(): string
    {
        return 'fingerprint';
    }

    public function computeFingerprint(ServerRequestInterface $request): string
    {
        $parts = [];

        foreach ($this->attributes as $attribute) {
            $headerName = self::HEADER_MAP[$attribute] ?? $attribute;
            $parts[] = $request->getHeaderLine($headerName);
        }

        $payload = implode('|', $parts);

        return $this->hmac->computeHex($payload, $this->hmacKey);
    }
}
