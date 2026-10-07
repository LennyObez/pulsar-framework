<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cloud\Azure;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cloud\Azure\Config\AzureConfig;
use Pulsar\Cloud\Azure\KeyVaultProvider;
use Pulsar\Cloud\CloudException;

#[CoversClass(KeyVaultProvider::class)]
final class KeyVaultProviderTest extends TestCase
{
    #[Test]
    public function getSecretThrowsOnMissingCredentials(): void
    {
        $config = new AzureConfig();
        $provider = new KeyVaultProvider($config, 'my-vault');

        $this->expectException(CloudException::class);
        $this->expectExceptionMessageMatches('/access token not configured/i');

        (void) $provider->getSecret('db-password');
    }

    #[Test]
    public function getSecretsIteratesOverNames(): void
    {
        $config = new AzureConfig();
        $provider = new KeyVaultProvider($config, 'my-vault');

        $this->expectException(CloudException::class);

        (void) $provider->getSecrets(['secret-1', 'secret-2']);
    }
}
