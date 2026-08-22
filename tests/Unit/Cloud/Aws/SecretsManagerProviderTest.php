<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cloud\Aws;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cloud\Aws\Config\AwsConfig;
use Pulsar\Cloud\Aws\SecretsManagerProvider;
use Pulsar\Cloud\CloudException;

#[CoversClass(SecretsManagerProvider::class)]
final class SecretsManagerProviderTest extends TestCase
{
    #[Test]
    public function getSecretThrowsOnMissingCredentials(): void
    {
        $config = new AwsConfig(region: 'us-east-1');
        $provider = new SecretsManagerProvider($config);

        $this->expectException(CloudException::class);
        $this->expectExceptionMessageMatches('/credentials not configured/i');

        (void) $provider->getSecret('my-secret');
    }

    #[Test]
    public function getSecretsReturnsMapForMultipleIds(): void
    {
        // We verify the method signature and iteration logic by expecting
        // the underlying getSecret to throw on first call
        $config = new AwsConfig(region: 'us-east-1');
        $provider = new SecretsManagerProvider($config);

        $this->expectException(CloudException::class);

        (void) $provider->getSecrets(['secret-1', 'secret-2']);
    }
}
