<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Internal\Seal;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Eidas\Contracts\ElectronicSealServiceInterface;
use Pulsar\Extension\Eidas\Domain\SealInfo;
use Pulsar\Extension\Eidas\Domain\SignatureFormat;
use Pulsar\Extension\Eidas\Exception\EidasException;

use function hash_hmac;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * HMAC-based electronic seal service for development and testing.
 */
#[Internal(reason: 'Use ElectronicSealServiceInterface with a production-grade implementation')]
final readonly class HmacSealService implements ElectronicSealServiceInterface
{
    /** @var array<string, array{key: string, name: string, id: string}> */
    private array $sealKeys;

    /**
     * @param array<string, array{key: string, name: string, id: string}> $sealKeys Map of key ID => {key, name, id}
     */
    public function __construct(array $sealKeys = [])
    {
        $this->sealKeys = $sealKeys;
    }

    #[Override]
    public function seal(string $data, string $sealKeyId, SignatureFormat $format = SignatureFormat::JAdES): string
    {
        $config = $this->sealKeys[$sealKeyId] ?? null;

        if ($config === null) {
            throw EidasException::sealVerificationFailed('Unknown seal key: ' . $sealKeyId);
        }

        $mac = hash_hmac('sha256', $data, $config['key']);
        $now = new DateTimeImmutable();

        return json_encode([
            'mac' => $mac,
            'org_name' => $config['name'],
            'org_id' => $config['id'],
            'seal_key_id' => $sealKeyId,
            'format' => $format->value,
            'sealed_at' => $now->format('Y-m-d\TH:i:s.uP'),
        ], JSON_THROW_ON_ERROR);
    }

    #[Override]
    public function verifySeal(string $data, string $seal, SignatureFormat $format = SignatureFormat::JAdES): SealInfo
    {
        /** @var array{mac?: string, org_name?: string, org_id?: string, seal_key_id?: string, format?: string, sealed_at?: string} $envelope */
        $envelope = json_decode($seal, true, 512, JSON_THROW_ON_ERROR);

        $sealKeyId = $envelope['seal_key_id'] ?? '';
        $orgName = $envelope['org_name'] ?? '';
        $orgId = $envelope['org_id'] ?? '';
        $expectedMac = $envelope['mac'] ?? '';
        $sealedAt = isset($envelope['sealed_at']) ? new DateTimeImmutable($envelope['sealed_at']) : new DateTimeImmutable();

        $config = $this->sealKeys[$sealKeyId] ?? null;

        if ($config === null) {
            return new SealInfo(
                valid: false,
                organizationName: $orgName,
                organizationId: $orgId,
                format: $format,
                isQualified: false,
                sealedAt: $sealedAt,
                reason: 'Unknown seal key',
            );
        }

        $actualMac = hash_hmac('sha256', $data, $config['key']);
        $valid = hash_equals($expectedMac, $actualMac);

        return new SealInfo(
            valid: $valid,
            organizationName: $orgName,
            organizationId: $orgId,
            format: $format,
            isQualified: false,
            sealedAt: $sealedAt,
            reason: $valid ? '' : 'MAC mismatch',
        );
    }
}
