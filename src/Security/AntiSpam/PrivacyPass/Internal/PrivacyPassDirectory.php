<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\PrivacyPass\Internal;

use Pulsar\Api\Internal;

use function array_keys;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;

/**
 * Parses a Privacy Pass issuer directory (RFC 9576 §4 / RFC 9578 §11.1).
 *
 * The directory is a JSON document advertising the issuer's currently valid
 * token keys:
 *
 *   { "token-keys": [ { "token-type": 2, "token-key": "<base64url SPKI>" }, ... ] }
 *
 * Only token type 0x0002 (Blind RSA, publicly verifiable) is extracted — the
 * one type the Origin can verify with a public key. Parsing is total: malformed
 * input yields an empty list rather than throwing.
 */
#[Internal]
final class PrivacyPassDirectory
{
    /**
     * @return list<string> base64url SPKI keys for token type 0x0002, de-duplicated
     */
    public static function parseKeys(string $json): array
    {
        /** @var mixed $data */
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }

        $tokenKeys = $data['token-keys'] ?? null;
        if (!is_array($tokenKeys)) {
            return [];
        }

        $keys = [];
        foreach ($tokenKeys as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            /** @var mixed $type */
            $type = $entry['token-type'] ?? null;
            /** @var mixed $key */
            $key = $entry['token-key'] ?? null;

            if (self::isBlindRsaType($type) && is_string($key) && $key !== '') {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    private static function isBlindRsaType(mixed $type): bool
    {
        if (is_int($type)) {
            return $type === PrivateToken::TYPE_BLIND_RSA;
        }

        // Some directories serialise the type as a string.
        return is_string($type) && $type === (string) PrivateToken::TYPE_BLIND_RSA;
    }
}
