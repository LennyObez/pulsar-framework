<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\PrivacyPass;

use Pulsar\Security\AntiSpam\PrivacyPass\TokenChallenge;

use function base64_encode;
use function hex2bin;
use function preg_replace;
use function rtrim;
use function strtr;
use function substr;

/**
 * The first Token Type 0x0002 (Blind RSA) test vector from RFC 9578 Appendix.
 *
 * Used as a known-answer test so the verifier is proven against the published
 * spec output, not just against itself.
 */
final class Rfc9578TestVector
{
    private const string ISSUER_NAME = 'issuer.example';
    private const string ORIGIN_INFO = 'origin.example';

    private const string SPKI_HEX = '30820152303d06092a864886f70d01010a3030a00d300b0609608648016503040202'
        . 'a11a301806092a864886f70d010108300b0609608648016503040202a2030201300382010f003082010a0282010100'
        . 'cb1aed6b6a95f5b1ce013a4cfcab25b94b2e64a23034e4250a7eab43c0df3a8c12993af12b111908d4b471bec31d4b6c9'
        . 'ad9cdda90612a2ee903523e6de5a224d6b02f09e5c374d0cfe01d8f529c500a78a2f67908fa682b5a2b430c81eaf1af72'
        . 'd7b5e794fc98a3139276879757ce453b526ef9bf6ceb99979b8423b90f4461a22af37aab0cf5733f7597abe44d31c732d'
        . 'b68a181c6cbbe607d8c0e52e0655fd9996dc584eca0be87afbcd78a337d17b1dba9e828bbd81e291317144e7ff89f5561'
        . '9709b096cbb9ea474cead264c2073fe49740c01f00e109106066983d21e5f83f086e2e823c879cd43cef700d2a352a9ba'
        . 'bd612d03cad02db134b7e225a5f0203010001';

    private const string CHALLENGE_HEX = '0002000e6973737565722e6578616d706c65208e7acc900e393381e8810b7c9e4a68b5'
        . '163f1f880ab6688a6ffe780923609e88000e6f726967696e2e6578616d706c65';

    private const string TOKEN_HEX = '0002aa72019d1f951df197021ce63876fe8b0a02dc1c31a12b0a2dd1508d07827f05'
        . '5969f643b4cfda5196d4aa86aeb5368834f4f06de46950ed435b3b81bd036d44'
        . 'ca572f8982a9ca248a3056186322d93ca147266121ddeb5632c07f1f71cd2708'
        . 'bc6a21b533d07294b5e900faf5537dd3eb33cee4e08c9670d1e5358fd184b0e00c637174f5206b14c7bb0e724ebf6b562'
        . '71e5aa2ed94c051c4a433d302b23bc52460810d489fb050f9de5c868c6c1b06e3849fd087629f704cc724bc0d0984d5c3'
        . '39686fcdd75f9a9cdd25f37f855f6f4c584d84f716864f546b696d620c5bd41a811498de84ff9740ba3003ba2422d26b9'
        . '1eb745c084758974642a42078201543246ddb58030ea8e722376aa82484dca9610a8fb7e018e396165462e17a03e40ea7'
        . 'e128c090a911ecc708066cb201833010c1ebd4e910fc8e27a1be467f78671836a508257123a45e4e0ae2180a434bd1037'
        . '713466347a8ebe46439d3da1970';

    public static function spkiDer(): string
    {
        return self::bin(self::SPKI_HEX);
    }

    public static function spkiBase64Url(): string
    {
        return rtrim(strtr(base64_encode(self::spkiDer()), '+/', '-_'), '=');
    }

    public static function tokenChallengeWire(): string
    {
        return self::bin(self::CHALLENGE_HEX);
    }

    public static function token(): string
    {
        return self::bin(self::TOKEN_HEX);
    }

    public static function tokenBase64Url(): string
    {
        return rtrim(strtr(base64_encode(self::token()), '+/', '-_'), '=');
    }

    /**
     * The exact challenge the vector's token is bound to (32-byte context).
     */
    public static function challenge(): TokenChallenge
    {
        $context = substr(self::tokenChallengeWire(), 2 + 2 + 14 + 1, 32);

        return new TokenChallenge(0x0002, self::ISSUER_NAME, self::ORIGIN_INFO, $context);
    }

    private static function bin(string $hex): string
    {
        return (string) hex2bin((string) preg_replace('/\s+/', '', $hex));
    }
}
