<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
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
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;

use function is_string;

/**
 * Service wiring for cloud provider adapters (AWS, GCP, Azure).
 *
 * Registers cloud provider configs and service factories based on
 * the application's cloud configuration. Only registers adapters
 * for the configured provider(s).
 */
#[Internal]
final readonly class CloudWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();

        if (!$repository->has(CloudConfig::class)) {
            return;
        }

        /** @var CloudConfig $cloudConfig */
        $cloudConfig = $repository->get(CloudConfig::class);
        $container->instance(CloudConfig::class, $cloudConfig);

        $httpClient = new CloudHttpClient($cloudConfig->httpTimeout);
        $container->instance(CloudHttpClient::class, $httpClient);

        // AWS services
        if ($cloudConfig->aws !== []) {
            $this->wireAws($container, $cloudConfig->aws, $httpClient);
        }

        // GCP services
        if ($cloudConfig->gcp !== []) {
            $this->wireGcp($container, $cloudConfig->gcp, $httpClient);
        }

        // Azure services
        if ($cloudConfig->azure !== []) {
            $this->wireAzure($container, $cloudConfig->azure, $httpClient);
        }
    }

    /**
     * @param array<string, mixed> $awsData
     */
    private function wireAws(ContainerInterface $container, array $awsData, CloudHttpClient $httpClient): void
    {
        $awsConfig = AwsConfig::fromArray($awsData);
        $container->instance(AwsConfig::class, $awsConfig);

        // S3 Storage
        /** @var mixed $rawBucket */
        $rawBucket = $awsData['s3_bucket'] ?? '';
        /** @var mixed $rawPrefix */
        $rawPrefix = $awsData['s3_prefix'] ?? '';

        if (is_string($rawBucket) && $rawBucket !== '') {
            $container->bind(
                S3StorageAdapter::class,
                static fn(): S3StorageAdapter => new S3StorageAdapter(
                    config: $awsConfig,
                    bucket: $rawBucket,
                    prefix: is_string($rawPrefix) ? $rawPrefix : '',
                    httpClient: $httpClient,
                ),
            );
        }

        // SQS Queue
        /** @var mixed $rawSqsPrefix */
        $rawSqsPrefix = $awsData['sqs_prefix'] ?? '';
        /** @var mixed $rawFifo */
        $rawFifo = $awsData['sqs_fifo'] ?? false;

        $container->bind(
            SqsQueueDriver::class,
            static fn(): SqsQueueDriver => new SqsQueueDriver(
                config: $awsConfig,
                queuePrefix: is_string($rawSqsPrefix) ? $rawSqsPrefix : '',
                fifo: (bool) $rawFifo,
                httpClient: $httpClient,
            ),
        );

        // SES Mail
        $container->bind(
            SesMailTransport::class,
            static fn(): SesMailTransport => new SesMailTransport(
                config: $awsConfig,
                httpClient: $httpClient,
            ),
        );

        // SNS Notifications
        $container->bind(
            SnsNotificationChannel::class,
            static fn(): SnsNotificationChannel => new SnsNotificationChannel(
                config: $awsConfig,
                httpClient: $httpClient,
            ),
        );

        // Secrets Manager
        $container->bind(
            SecretsManagerProvider::class,
            static fn(): SecretsManagerProvider => new SecretsManagerProvider(
                config: $awsConfig,
                httpClient: $httpClient,
            ),
        );
    }

    /**
     * @param array<string, mixed> $gcpData
     */
    private function wireGcp(ContainerInterface $container, array $gcpData, CloudHttpClient $httpClient): void
    {
        $gcpConfig = GcpConfig::fromArray($gcpData);
        $container->instance(GcpConfig::class, $gcpConfig);

        // GCS Storage
        /** @var mixed $rawBucket */
        $rawBucket = $gcpData['gcs_bucket'] ?? '';
        /** @var mixed $rawPrefix */
        $rawPrefix = $gcpData['gcs_prefix'] ?? '';

        if (is_string($rawBucket) && $rawBucket !== '') {
            $container->bind(
                GcsStorageAdapter::class,
                static fn(): GcsStorageAdapter => new GcsStorageAdapter(
                    config: $gcpConfig,
                    bucket: $rawBucket,
                    prefix: is_string($rawPrefix) ? $rawPrefix : '',
                    httpClient: $httpClient,
                ),
            );
        }

        // Pub/Sub Queue
        $container->bind(
            PubSubQueueDriver::class,
            static fn(): PubSubQueueDriver => new PubSubQueueDriver(
                config: $gcpConfig,
                httpClient: $httpClient,
            ),
        );

        // Firestore Sessions
        $container->bind(
            FirestoreSessionHandler::class,
            static fn(): FirestoreSessionHandler => new FirestoreSessionHandler(
                config: $gcpConfig,
                httpClient: $httpClient,
            ),
        );

        // Secret Manager
        $container->bind(
            SecretManagerProvider::class,
            static fn(): SecretManagerProvider => new SecretManagerProvider(
                config: $gcpConfig,
                httpClient: $httpClient,
            ),
        );
    }

    /**
     * @param array{
     *     tenant_id?: string,
     *     client_id?: string,
     *     client_secret?: string,
     *     endpoint?: string|null,
     *     access_token?: string|null,
     *     storage_account?: string,
     *     blob_container?: string,
     *     blob_prefix?: string,
     *     servicebus_namespace?: string,
     *     servicebus_queue?: string,
     *     keyvault_uri?: string,
     * } $azureData
     */
    private function wireAzure(ContainerInterface $container, array $azureData, CloudHttpClient $httpClient): void
    {
        $azureConfig = AzureConfig::fromArray($azureData);
        $container->instance(AzureConfig::class, $azureConfig);

        // Blob Storage
        $storageAccount = $azureData['storage_account'] ?? '';
        $blobContainer = $azureData['blob_container'] ?? '';

        if ($storageAccount !== '' && $blobContainer !== '') {
            $prefix = $azureData['blob_prefix'] ?? '';

            $container->bind(
                BlobStorageAdapter::class,
                static fn(): BlobStorageAdapter => new BlobStorageAdapter(
                    config: $azureConfig,
                    storageAccount: $storageAccount,
                    container: $blobContainer,
                    prefix: $prefix,
                    httpClient: $httpClient,
                ),
            );
        }

        // Service Bus Queue
        $serviceBusNamespace = $azureData['servicebus_namespace'] ?? '';

        if ($serviceBusNamespace !== '') {
            $container->bind(
                ServiceBusQueueDriver::class,
                static fn(): ServiceBusQueueDriver => new ServiceBusQueueDriver(
                    config: $azureConfig,
                    namespace: $serviceBusNamespace,
                    httpClient: $httpClient,
                ),
            );
        }

        // Cosmos DB Sessions
        /** @var array{cosmos_account?: string, cosmos_database?: string, keyvault_name?: string} $azureData */
        $cosmosAccount = $azureData['cosmos_account'] ?? '';
        $cosmosDb = $azureData['cosmos_database'] ?? '';

        if ($cosmosAccount !== '' && $cosmosDb !== '') {
            $container->bind(
                CosmosDbSessionHandler::class,
                static fn(): CosmosDbSessionHandler => new CosmosDbSessionHandler(
                    config: $azureConfig,
                    accountName: $cosmosAccount,
                    databaseId: $cosmosDb,
                    httpClient: $httpClient,
                ),
            );
        }

        // Key Vault
        $vaultName = $azureData['keyvault_name'] ?? '';

        if ($vaultName !== '') {
            $container->bind(
                KeyVaultProvider::class,
                static fn(): KeyVaultProvider => new KeyVaultProvider(
                    config: $azureConfig,
                    vaultName: $vaultName,
                    httpClient: $httpClient,
                ),
            );
        }
    }
}
