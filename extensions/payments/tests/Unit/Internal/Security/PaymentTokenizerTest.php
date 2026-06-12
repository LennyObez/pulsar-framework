<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Internal\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Internal\Security\PaymentTokenizer;

use function bin2hex;
use function putenv;
use function str_repeat;
use function strlen;

final class PaymentTokenizerTest extends TestCase
{
    protected function setUp(): void
    {
        // PaymentTokenizer reads PULSAR_PCI_TOKENIZER_KEY via getenv() and
        // refuses to operate without it. Provide a deterministic 32-byte
        // (64 hex char) test key so the hashing/fingerprint tests can run.
        putenv('PULSAR_PCI_TOKENIZER_KEY=' . bin2hex(str_repeat("\x2a", 32)));
    }

    protected function tearDown(): void
    {
        putenv('PULSAR_PCI_TOKENIZER_KEY');
    }

    #[Test]
    public function generate_token_uses_default_prefix(): void
    {
        $token = PaymentTokenizer::generateToken();

        self::assertStringStartsWith('tok_', $token);
        self::assertSame(36, strlen($token)); // 'tok_' + 32 hex chars
    }

    #[Test]
    public function generate_token_uses_custom_prefix(): void
    {
        $token = PaymentTokenizer::generateToken('pm');

        self::assertStringStartsWith('pm_', $token);
    }

    #[Test]
    public function generate_token_produces_unique_values(): void
    {
        $token1 = PaymentTokenizer::generateToken();
        $token2 = PaymentTokenizer::generateToken();

        self::assertNotSame($token1, $token2);
    }

    #[Test]
    public function hash_purchase_token_returns_sha256_hash(): void
    {
        $hash = PaymentTokenizer::hashPurchaseToken('test-token');

        self::assertSame(64, strlen($hash));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    #[Test]
    public function hash_purchase_token_is_deterministic(): void
    {
        $hash1 = PaymentTokenizer::hashPurchaseToken('test-token');
        $hash2 = PaymentTokenizer::hashPurchaseToken('test-token');

        self::assertSame($hash1, $hash2);
    }

    #[Test]
    public function hash_purchase_token_differs_for_different_inputs(): void
    {
        $hash1 = PaymentTokenizer::hashPurchaseToken('token-a');
        $hash2 = PaymentTokenizer::hashPurchaseToken('token-b');

        self::assertNotSame($hash1, $hash2);
    }

    #[Test]
    public function fingerprint_returns_32_char_hex_string(): void
    {
        $fingerprint = PaymentTokenizer::fingerprint('card', '4242', '12', '2028');

        self::assertSame(32, strlen($fingerprint));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $fingerprint);
    }

    #[Test]
    public function fingerprint_is_deterministic(): void
    {
        $fp1 = PaymentTokenizer::fingerprint('card', '4242', '12', '2028');
        $fp2 = PaymentTokenizer::fingerprint('card', '4242', '12', '2028');

        self::assertSame($fp1, $fp2);
    }

    #[Test]
    public function fingerprint_differs_for_different_last4(): void
    {
        $fp1 = PaymentTokenizer::fingerprint('card', '4242', '12', '2028');
        $fp2 = PaymentTokenizer::fingerprint('card', '1234', '12', '2028');

        self::assertNotSame($fp1, $fp2);
    }

    #[Test]
    public function fingerprint_differs_for_different_method_type(): void
    {
        $fp1 = PaymentTokenizer::fingerprint('card', '4242', '12', '2028');
        $fp2 = PaymentTokenizer::fingerprint('sepa', '4242', '12', '2028');

        self::assertNotSame($fp1, $fp2);
    }
}
