<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Mobile;

use JsonException;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Exception\PaymentException;

use function base64_decode;
use function count;
use function explode;
use function is_array;
use function is_string;
use function json_decode;
use function openssl_pkey_get_public;
use function openssl_verify;
use function openssl_x509_parse;
use function openssl_x509_read;
use function str_pad;
use function strlen;
use function strtr;

use const JSON_THROW_ON_ERROR;
use const OPENSSL_ALGO_SHA256;

/**
 * Verifies Apple JWS (JSON Web Signature) payloads using the x5c certificate chain.
 *
 * Apple's App Store Server API and Server Notifications v2 use ES256-signed JWS
 * tokens with an x5c header containing the signing certificate chain. This class
 * decodes the payload only after verifying the signature against the public key
 * extracted from the first certificate in the chain.
 */
#[Internal]
final class JwsVerifier
{
    /**
     * Verify a JWS signature and return the decoded payload.
     *
     * @return array<string, mixed> The decoded payload
     *
     * @throws PaymentException If the JWS structure is invalid or signature verification fails
     */
    public static function verifyAndDecode(string $jws): array
    {
        if ($jws === '') {
            throw PaymentException::jwsVerificationFailed('empty JWS token');
        }

        $parts = explode('.', $jws);

        if (count($parts) !== 3) {
            throw PaymentException::jwsVerificationFailed('JWS must have exactly 3 parts');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $headerJson = self::base64UrlDecode($encodedHeader);

        if ($headerJson === false || $headerJson === '') {
            throw PaymentException::jwsVerificationFailed('invalid base64url in header');
        }

        try {
            /** @var array{alg?: string, x5c?: list<string>}|null $header */
            $header = json_decode($headerJson, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw PaymentException::jwsVerificationFailed('header contains invalid JSON');
        }

        if (!is_array($header)) {
            throw PaymentException::jwsVerificationFailed('header is not a JSON object');
        }

        $rawAlg = $header['alg'] ?? null;
        $alg = is_string($rawAlg) ? $rawAlg : '';

        if ($alg !== 'ES256') {
            throw PaymentException::jwsVerificationFailed(
                "unsupported algorithm: expected ES256, got {$alg}",
            );
        }

        $rawX5c = $header['x5c'] ?? null;
        /** @var list<string> $x5c */
        $x5c = is_array($rawX5c) ? $rawX5c : [];

        if ($x5c === []) {
            throw PaymentException::jwsVerificationFailed('x5c certificate chain is missing');
        }

        $leafCertDer = $x5c[0];

        if (!is_string($leafCertDer) || $leafCertDer === '') {
            throw PaymentException::jwsVerificationFailed('x5c[0] certificate is empty');
        }

        $pem = "-----BEGIN CERTIFICATE-----\n"
            . chunk_split($leafCertDer, 64, "\n")
            . '-----END CERTIFICATE-----';

        $certResource = openssl_x509_read($pem);

        if ($certResource === false) {
            throw PaymentException::jwsVerificationFailed('failed to parse x5c[0] certificate');
        }

        $parsed = openssl_x509_parse($certResource);

        if ($parsed === false) {
            throw PaymentException::jwsVerificationFailed('failed to parse certificate details');
        }

        $publicKey = openssl_pkey_get_public($certResource);

        if ($publicKey === false) {
            throw PaymentException::jwsVerificationFailed(
                'failed to extract public key from certificate',
            );
        }

        $signature = self::base64UrlDecode($encodedSignature);

        if ($signature === false) {
            throw PaymentException::jwsVerificationFailed('invalid base64url in signature');
        }

        $signingInput = "{$encodedHeader}.{$encodedPayload}";

        $verified = openssl_verify($signingInput, $signature, $publicKey, OPENSSL_ALGO_SHA256);

        if ($verified !== 1) {
            throw PaymentException::jwsVerificationFailed('signature does not match');
        }

        $payloadJson = self::base64UrlDecode($encodedPayload);

        if ($payloadJson === false) {
            throw PaymentException::jwsVerificationFailed('invalid base64url in payload');
        }

        try {
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($payloadJson, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw PaymentException::jwsVerificationFailed('payload contains invalid JSON');
        }

        return is_array($decoded) ? $decoded : [];
    }

    private static function base64UrlDecode(string $data): string|false
    {
        $padded = str_pad($data, strlen($data) + (4 - strlen($data) % 4) % 4, '=');

        return base64_decode(strtr($padded, '-_', '+/'), true);
    }
}
