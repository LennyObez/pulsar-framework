<?php

declare(strict_types=1);

namespace Pulsar\Security\Jws;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function dirname;
use function file_get_contents;
use function trim;

use const DIRECTORY_SEPARATOR;

/**
 * Builds a {@see JwsVerifierInterface} pinned to the bundled Apple Root CA G3.
 *
 * Apple App Store Server API / Server Notifications v2 payloads are ES256 JWS
 * signed by a leaf → Apple WWDR intermediate → Apple Root CA G3 chain. This
 * factory reads the framework-bundled root certificate
 * (`resources/security/apple/AppleRootCA-G3.pem`) as the single pinned trust
 * anchor and returns a verifier configured with it, so both the payments and
 * subscriptions extensions share one correct, offline-pinned implementation.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final class AppleJwsVerifierFactory
{
    private static ?JwsVerifierInterface $verifier = null;

    /**
     * Return a verifier pinned to the bundled Apple Root CA G3.
     *
     * @throws RuntimeException When the bundled trust anchor is missing or empty
     *                          (fail closed: never verify against no anchor).
     */
    #[NoDiscard]
    public static function create(): JwsVerifierInterface
    {
        if (self::$verifier !== null) {
            return self::$verifier;
        }

        $path = dirname(__DIR__, 3)
            . DIRECTORY_SEPARATOR . 'resources'
            . DIRECTORY_SEPARATOR . 'security'
            . DIRECTORY_SEPARATOR . 'apple'
            . DIRECTORY_SEPARATOR . 'AppleRootCA-G3.pem';

        $pem = @file_get_contents($path);

        if ($pem === false || trim($pem) === '') {
            throw new RuntimeException('Apple Root CA G3 trust anchor is missing at ' . $path);
        }

        return self::$verifier = new X5cChainJwsVerifier([$pem]);
    }
}
