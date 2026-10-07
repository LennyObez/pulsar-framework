<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cloud\Gcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cloud\CloudException;
use Pulsar\Cloud\Gcp\Config\GcpConfig;
use Pulsar\Cloud\Gcp\SecretManagerProvider;

#[CoversClass(SecretManagerProvider::class)]
final class SecretManagerProviderTest extends TestCase
{
    #[Test]
    public function getSecretThrowsOnMissingCredentials(): void
    {
        $config = new GcpConfig(projectId: 'test');
        $provider = new SecretManagerProvider($config);

        $this->expectException(CloudException::class);
        $this->expectExceptionMessageMatches('/access token not configured/i');

        (void) $provider->getSecret('db-password');
    }

    #[Test]
    public function getSecretsIteratesOverIds(): void
    {
        $config = new GcpConfig(projectId: 'test');
        $provider = new SecretManagerProvider($config);

        $this->expectException(CloudException::class);

        (void) $provider->getSecrets(['secret-1', 'secret-2']);
    }
}
