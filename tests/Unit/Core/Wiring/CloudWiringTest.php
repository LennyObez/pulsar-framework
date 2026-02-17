<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cloud\Aws\Config\AwsConfig;
use Pulsar\Cloud\Aws\S3StorageAdapter;
use Pulsar\Cloud\Aws\SecretsManagerProvider;
use Pulsar\Cloud\Aws\SesMailTransport;
use Pulsar\Cloud\Aws\SnsNotificationChannel;
use Pulsar\Cloud\Aws\SqsQueueDriver;
use Pulsar\Cloud\Azure\BlobStorageAdapter;
use Pulsar\Cloud\Azure\Config\AzureConfig;
use Pulsar\Cloud\Azure\CosmosDbSessionHandler;
use Pulsar\Cloud\Azure\KeyVaultProvider;
use Pulsar\Cloud\Azure\ServiceBusQueueDriver;
use Pulsar\Cloud\CloudConfig;
use Pulsar\Cloud\CloudHttpClient;
use Pulsar\Cloud\Gcp\Config\GcpConfig;
use Pulsar\Cloud\Gcp\FirestoreSessionHandler;
use Pulsar\Cloud\Gcp\GcsStorageAdapter;
use Pulsar\Cloud\Gcp\PubSubQueueDriver;
use Pulsar\Cloud\Gcp\SecretManagerProvider;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\CloudWiring;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;

use function file_put_contents;
use function mkdir;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(CloudWiring::class)]
final class CloudWiringTest extends TestCase
{
    private string $configPath = '';

    protected function setUp(): void
    {
        $this->configPath = sys_get_temp_dir() . '/pulsar_cloud_wiring_test_' . bin2hex(random_bytes(4));
        @mkdir($this->configPath, 0o755, true);

        // Minimal required config files
        file_put_contents($this->configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($this->configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []], "audit" => ["enabled" => false]];');
        file_put_contents($this->configPath . '/security.php', '<?php return ["session" => ["handler" => "file", "lifetime" => 120, "encryption" => false, "validators" => []], "csrf" => [], "headers" => [], "rate_limit" => [], "cors" => ["enabled" => false]];');
    }

    protected function tearDown(): void
    {
        @unlink($this->configPath . '/app.php');
        @unlink($this->configPath . '/observability.php');
        @unlink($this->configPath . '/security.php');
        @unlink($this->configPath . '/cloud.php');
        @rmdir($this->configPath);
    }

    #[Test]
    public function skipWiringWhenNoCloudConfig(): void
    {
        $wiring = new CloudWiring();
        $container = new Container();
        $configManager = new ConfigManager($this->configPath);
        $configManager->load();
        $middleware = new MiddlewarePipeline($container);
        $registry = new MiddlewareRegistry();
        $router = new Router();

        $wiring->wire($container, $configManager, $middleware, $registry, $router);

        self::assertFalse($container->has(CloudConfig::class));
    }

    #[Test]
    public function wiresAwsServicesWhenConfigured(): void
    {
        file_put_contents($this->configPath . '/cloud.php', '<?php return ["default_provider" => "aws", "aws" => ["region" => "us-east-1", "access_key" => "AKID", "secret_key" => "SECRET", "s3_bucket" => "my-bucket"]];');

        $wiring = new CloudWiring();
        $container = new Container();
        $configManager = new ConfigManager($this->configPath);
        $configManager->load();
        $middleware = new MiddlewarePipeline($container);
        $registry = new MiddlewareRegistry();
        $router = new Router();

        $wiring->wire($container, $configManager, $middleware, $registry, $router);

        self::assertTrue($container->has(CloudConfig::class));
        self::assertTrue($container->has(CloudHttpClient::class));
        self::assertTrue($container->has(AwsConfig::class));
        self::assertTrue($container->has(S3StorageAdapter::class));
        self::assertTrue($container->has(SqsQueueDriver::class));
        self::assertTrue($container->has(SesMailTransport::class));
        self::assertTrue($container->has(SnsNotificationChannel::class));
        self::assertTrue($container->has(SecretsManagerProvider::class));
    }

    #[Test]
    public function wiresGcpServicesWhenConfigured(): void
    {
        file_put_contents($this->configPath . '/cloud.php', '<?php return ["default_provider" => "gcp", "gcp" => ["project_id" => "my-project", "gcs_bucket" => "my-bucket"]];');

        $wiring = new CloudWiring();
        $container = new Container();
        $configManager = new ConfigManager($this->configPath);
        $configManager->load();
        $middleware = new MiddlewarePipeline($container);
        $registry = new MiddlewareRegistry();
        $router = new Router();

        $wiring->wire($container, $configManager, $middleware, $registry, $router);

        self::assertTrue($container->has(GcpConfig::class));
        self::assertTrue($container->has(GcsStorageAdapter::class));
        self::assertTrue($container->has(PubSubQueueDriver::class));
        self::assertTrue($container->has(FirestoreSessionHandler::class));
        self::assertTrue($container->has(SecretManagerProvider::class));
    }

    #[Test]
    public function wiresAzureServicesWhenConfigured(): void
    {
        file_put_contents($this->configPath . '/cloud.php', '<?php return ["default_provider" => "azure", "azure" => ["tenant_id" => "tenant-abc", "storage_account" => "myaccount", "blob_container" => "files", "servicebus_namespace" => "my-ns", "cosmos_account" => "mycosmosaccount", "cosmos_database" => "mydb", "keyvault_name" => "my-vault"]];');

        $wiring = new CloudWiring();
        $container = new Container();
        $configManager = new ConfigManager($this->configPath);
        $configManager->load();
        $middleware = new MiddlewarePipeline($container);
        $registry = new MiddlewareRegistry();
        $router = new Router();

        $wiring->wire($container, $configManager, $middleware, $registry, $router);

        self::assertTrue($container->has(AzureConfig::class));
        self::assertTrue($container->has(BlobStorageAdapter::class));
        self::assertTrue($container->has(ServiceBusQueueDriver::class));
        self::assertTrue($container->has(CosmosDbSessionHandler::class));
        self::assertTrue($container->has(KeyVaultProvider::class));
    }
}
