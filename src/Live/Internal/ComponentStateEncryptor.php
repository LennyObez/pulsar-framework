<?php

declare(strict_types=1);

namespace Pulsar\Live\Internal;

use Pulsar\Api\Internal;
use Pulsar\Security\Crypto\EncryptorInterface;
use SodiumException;

use function base64_decode;
use function base64_encode;
use function hash;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Encrypts and signs component state for secure round-trips.
 *
 * The state payload sent to the browser is encrypted with AES-256-GCM
 * and authenticated, preventing tampering or state forgery.
 */
#[Internal]
final readonly class ComponentStateEncryptor
{
    public function __construct(
        private EncryptorInterface $encryptor,
    ) {}

    /**
     * Encrypt and sign component state for the frontend.
     *
     * @param string $componentName Component class name
     * @param array<string, mixed> $state Component state
     * @return string Base64-encoded encrypted payload
     */
    public function encrypt(string $componentName, array $state): string
    {
        $payload = json_encode([
            'component' => $componentName,
            'state' => $state,
            'checksum' => $this->checksum($componentName, $state),
        ], JSON_THROW_ON_ERROR);

        return base64_encode($this->encryptor->encrypt($payload));
    }

    /**
     * Decrypt and verify component state from the frontend.
     *
     * @return array{component: string, state: array<string, mixed>}|null
     */
    public function decrypt(string $encrypted): ?array
    {
        $ciphertext = base64_decode($encrypted, true);

        if ($ciphertext === false) {
            return null;
        }

        try {
            $plaintext = $this->encryptor->decrypt($ciphertext);
        } catch (SodiumException) {
            return null;
        }

        /** @var array{component?: string, state?: array<string, mixed>, checksum?: string} $data */
        $data = json_decode($plaintext, true);

        if (!isset($data['component'], $data['state'], $data['checksum'])) {
            return null;
        }

        /** @var string $component */
        $component = $data['component'];
        /** @var array<string, mixed> $state */
        $state = $data['state'];

        $expectedChecksum = $this->checksum($component, $state);

        if (!hash_equals($expectedChecksum, $data['checksum'])) {
            return null;
        }

        return [
            'component' => $component,
            'state' => $state,
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function checksum(string $componentName, array $state): string
    {
        $payload = $componentName . ':' . json_encode($state, JSON_THROW_ON_ERROR);

        return hash('sha256', $payload);
    }
}
