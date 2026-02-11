<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Encryption;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Encryption\MailEncryptorInterface;
use Pulsar\Mail\Encryption\PgpEncryptor;
use Pulsar\Mail\Encryption\SmimeEncryptor;
use ReflectionClass;

use function extension_loaded;
use function function_exists;

#[CoversClass(SmimeEncryptor::class)]
#[CoversClass(PgpEncryptor::class)]
final class MailEncryptorInterfaceTest extends TestCase
{
    #[Test]
    public function smimeEncryptorImplementsInterface(): void
    {
        $reflection = new ReflectionClass(SmimeEncryptor::class);

        self::assertTrue($reflection->implementsInterface(MailEncryptorInterface::class));
    }

    #[Test]
    public function pgpEncryptorImplementsInterface(): void
    {
        $reflection = new ReflectionClass(PgpEncryptor::class);

        self::assertTrue($reflection->implementsInterface(MailEncryptorInterface::class));
    }

    #[Test]
    public function smimeEncryptorCanBeInstantiated(): void
    {
        if (!function_exists('openssl_pkcs7_encrypt')) {
            self::markTestSkipped('OpenSSL extension is not available');
        }

        $encryptor = new SmimeEncryptor();

        self::assertInstanceOf(MailEncryptorInterface::class, $encryptor);
    }

    #[Test]
    public function pgpEncryptorCanBeInstantiated(): void
    {
        if (!extension_loaded('gnupg')) {
            self::markTestSkipped('gnupg extension is not available');
        }

        $encryptor = new PgpEncryptor();

        self::assertInstanceOf(MailEncryptorInterface::class, $encryptor);
    }
}
