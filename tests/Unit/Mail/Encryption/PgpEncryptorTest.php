<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Encryption;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Encryption\EncryptionType;
use Pulsar\Mail\Encryption\PgpEncryptor;
use Pulsar\Mail\Exception\MailException;

use function extension_loaded;

#[CoversClass(PgpEncryptor::class)]
final class PgpEncryptorTest extends TestCase
{
    #[Test]
    public function throwsWhenGnupgExtensionIsNotLoaded(): void
    {
        if (extension_loaded('gnupg')) {
            self::markTestSkipped('gnupg extension is loaded — cannot test unavailability');
        }

        $this->expectException(MailException::class);
        $this->expectExceptionMessageMatches('/gnupg/i');

        new PgpEncryptor();
    }

    #[Test]
    public function returnsPgpEncryptionType(): void
    {
        if (!extension_loaded('gnupg')) {
            self::markTestSkipped('gnupg extension is not available');
        }

        $encryptor = new PgpEncryptor();

        self::assertSame(EncryptionType::Pgp, $encryptor->type());
    }
}
