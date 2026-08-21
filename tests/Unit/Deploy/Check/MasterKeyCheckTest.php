<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Deploy\Check;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Container\Container;
use Pulsar\Deploy\Check\MasterKeyCheck;
use Pulsar\Deploy\CheckSeverity;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Crypto\MasterKeyFailure;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * The gate that refuses a deployment whose at-rest protections are absent.
 *
 * The scenario each test encodes is the one the shipped templates produce: the
 * key line exists and is empty, nothing throws, and every subsystem derived from
 * it is simply never registered.
 */
#[CoversClass(MasterKeyCheck::class)]
final class MasterKeyCheckTest extends TestCase
{
    /** 64 hex characters — the shape MasterKey::fromHex accepts. */
    private const string VALID_KEY_HEX = '4d61737465724b65790000000000000000000000000000000000000000000001';

    private string $envFile = '';

    protected function setUp(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pulsar-env-');
        self::assertIsString($file);
        $this->envFile = $file;

        // The OS environment wins over the file, so a key exported by the
        // developer's shell would otherwise decide the result of every test here.
        putenv('PULSAR_MASTER_KEY');
    }

    protected function tearDown(): void
    {
        if ($this->envFile !== '') {
            @unlink($this->envFile);
        }

        putenv('PULSAR_MASTER_KEY');
    }

    #[Test]
    public function itReportsItsName(): void
    {
        self::assertSame('master-key', $this->check('')->getName());
    }

    #[Test]
    public function itReportsItsDescription(): void
    {
        self::assertSame(
            'Validates PULSAR_MASTER_KEY resolved a usable key in staging/production',
            $this->check('')->getDescription(),
        );
    }

    #[Test]
    public function itSkipsLocalWhereAnEmptyKeyIsTheDocumentedStartingPoint(): void
    {
        $result = $this->check('')->check('local');

        self::assertSame(CheckSeverity::Pass, $result->severity);
    }

    #[Test]
    public function itPassesWhenTheKeyResolvedAMasterKeyBinding(): void
    {
        $container = new Container();
        $container->instance(MasterKey::class, MasterKey::fromHex(self::VALID_KEY_HEX));

        $result = $this->check(self::VALID_KEY_HEX, $container)->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
    }

    #[Test]
    public function itFailsProductionWhenTheKeyIsEmpty(): void
    {
        $result = $this->check('')->check('production');

        self::assertSame(CheckSeverity::Error, $result->severity);
        self::assertStringContainsString('PULSAR_MASTER_KEY is not set', $result->message);
    }

    #[Test]
    public function itFailsStagingWhenTheKeyIsEmpty(): void
    {
        $result = $this->check('')->check('staging');

        self::assertSame(CheckSeverity::Error, $result->severity);
    }

    #[Test]
    public function itNamesEverySubsystemAnEmptyKeyWithholds(): void
    {
        $recommendations = $this->check('')->check('production')->recommendations;

        $joined = implode("\n", $recommendations);

        self::assertStringContainsString('EncryptorInterface', $joined);
        self::assertStringContainsString('SessionEncryption', $joined);
        self::assertStringContainsString('audit HMAC chain', $joined);
        self::assertStringContainsString('key:generate', $joined);
    }

    /**
     * A rejected key leaves the same empty container as a missing one, so a check
     * that only tested the variable for emptiness would wave it through.
     * SecurityWiring binds a MasterKeyFailure for exactly this case, and the gate
     * reports the reason the parse gave rather than guessing at the input again.
     */
    #[Test]
    public function itReportsWhyASuppliedKeyWasRejected(): void
    {
        $container = new Container();
        $container->instance(MasterKeyFailure::class, new MasterKeyFailure('expected 64 hex characters, got 15'));

        $result = $this->check('not-hexadecimal', $container)->check('production');

        self::assertSame(CheckSeverity::Error, $result->severity);
        self::assertStringContainsString('supplied', $result->message);
        self::assertStringContainsString('rejected', $result->message);
        self::assertStringContainsString('expected 64 hex characters, got 15', $result->message);
    }

    /**
     * A value present, no key bound, and no rejection recorded means the crypto
     * branch never ran. The remediation is a wiring question, so it is reported
     * apart from a bad key.
     */
    #[Test]
    public function itDistinguishesWiringThatNeverRanFromAKeyThatWasRefused(): void
    {
        $result = $this->check(self::VALID_KEY_HEX)->check('production');

        self::assertSame(CheckSeverity::Error, $result->severity);
        self::assertStringContainsString('no MasterKey was bound', $result->message);
    }

    private function check(string $keyValue, ?Container $container = null): MasterKeyCheck
    {
        file_put_contents($this->envFile, "PULSAR_MASTER_KEY=$keyValue\n");

        return new MasterKeyCheck(
            $container ?? new Container(),
            Environment::load($this->envFile),
        );
    }
}
