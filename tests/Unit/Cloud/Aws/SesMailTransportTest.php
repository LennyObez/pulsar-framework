<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cloud\Aws;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cloud\Aws\Config\AwsConfig;
use Pulsar\Cloud\Aws\SesMailTransport;
use Pulsar\Mail\Exception\MailException;

#[CoversClass(SesMailTransport::class)]
final class SesMailTransportTest extends TestCase
{
    #[Test]
    public function nameReturnsSes(): void
    {
        $config = new AwsConfig(region: 'us-east-1', accessKey: 'AKID', secretKey: 'SECRET');
        $transport = new SesMailTransport($config);

        self::assertSame('ses', $transport->name());
    }

    #[Test]
    public function missingCredentialsThrowsOnSend(): void
    {
        $config = new AwsConfig(region: 'us-east-1');
        $transport = new SesMailTransport($config);

        $message = new \Pulsar\Mail\Message(
            from: new \Pulsar\Mail\Address('sender@example.com'),
            to: [new \Pulsar\Mail\Address('recipient@example.com')],
            subject: 'Test',
            textBody: 'Hello',
        );

        $this->expectException(MailException::class);
        $this->expectExceptionMessageMatches('/credentials not configured/i');

        $transport->send($message);
    }
}
