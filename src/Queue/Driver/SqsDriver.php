<?php

declare(strict_types=1);

namespace Pulsar\Queue\Driver;

use Aws\Sqs\SqsClient;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Queue\Driver\Config\SqsDriverConfig;
use Pulsar\Queue\Exception\QueueException;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueDriverInterface;
use Random\Engine\Secure;
use Random\Randomizer;

use function bin2hex;
use function class_exists;
use function is_array;
use function json_decode;
use function json_encode;
use function min;
use function rtrim;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * Amazon SQS queue driver using the AWS SDK.
 *
 * Maps Pulsar queue names to SQS queue URLs via the configured prefix.
 * Supports delayed messages up to the SQS maximum of 900 seconds.
 * Receipt handles are tracked internally for acknowledge/reject operations.
 */
#[Internal(reason: 'Implementation detail — use QueueDriverInterface contract')]
final class SqsDriver implements QueueDriverInterface
{
    private const int MAX_SQS_DELAY_SECONDS = 900;

    private readonly Randomizer $randomizer;

    private ?SqsClient $client = null;

    /** @var array<string, string> Receipt handle indexed by job ID */
    private array $receiptHandles = [];

    /** @var array<string, string> Queue URL indexed by job ID */
    private array $jobQueueUrls = [];

    /** @var array<string, JobRecord> In-memory tracking for findByStatus */
    private array $knownJobs = [];

    public function __construct(
        private readonly SqsDriverConfig $config,
        ?Randomizer $randomizer = null,
    ) {
        if (!class_exists(SqsClient::class)) {
            throw QueueException::driverNotConfigured(
                'sqs (aws/aws-sdk-php is not installed)',
            );
        }

        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    #[Override]
    public function push(string $queue, string $jobClass, string $payload, int $delay = 0): string
    {
        $id = bin2hex($this->randomizer->getBytes(16));
        $now = time();

        $jobData = json_encode([
            'id' => $id,
            'queue' => $queue,
            'job_class' => $jobClass,
            'payload' => $payload,
            'attempts' => 0,
            'status' => JobRecordStatus::Pending->value,
            'created_at' => $now,
            'available_at' => $now + $delay,
        ], JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $params */
        $params = [
            'QueueUrl' => $this->queueUrl($queue),
            'MessageBody' => $jobData,
        ];

        if ($delay > 0) {
            $params['DelaySeconds'] = min($delay, self::MAX_SQS_DELAY_SECONDS);
        }

        $this->client()->sendMessage($params);

        $this->knownJobs[$id] = new JobRecord(
            id: $id,
            queue: $queue,
            jobClass: $jobClass,
            payload: $payload,
            attempts: 0,
            status: JobRecordStatus::Pending,
            createdAt: $now,
            availableAt: $now + $delay,
        );

        return $id;
    }

    #[Override]
    public function pop(string $queue): ?JobRecord
    {
        $queueUrl = $this->queueUrl($queue);

        $result = $this->client()->receiveMessage([
            'QueueUrl' => $queueUrl,
            'MaxNumberOfMessages' => 1,
            'WaitTimeSeconds' => 0,
            'AttributeNames' => ['ApproximateReceiveCount'],
        ]);

        /** @var list<array<string, mixed>>|null $messages */
        $messages = $result->get('Messages');

        if (!is_array($messages) || $messages === []) {
            return null;
        }

        $message = $messages[0];

        /** @var string $body */
        $body = $message['Body'];

        /** @var string $receiptHandle */
        $receiptHandle = $message['ReceiptHandle'];

        /** @var array{id: string, queue: string, job_class: string, payload: string, attempts: int, status: string, created_at: int, available_at: int} $data */
        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        /** @var array<string, string> $attributes */
        $attributes = $message['Attributes'] ?? [];
        $receiveCount = (int) ($attributes['ApproximateReceiveCount'] ?? ($data['attempts'] + 1));

        $this->receiptHandles[$data['id']] = $receiptHandle;
        $this->jobQueueUrls[$data['id']] = $queueUrl;

        $record = new JobRecord(
            id: $data['id'],
            queue: $data['queue'],
            jobClass: $data['job_class'],
            payload: $data['payload'],
            attempts: $receiveCount,
            status: JobRecordStatus::Processing,
            createdAt: $data['created_at'],
            availableAt: $data['available_at'],
        );

        $this->knownJobs[$data['id']] = $record;

        return $record;
    }

    #[Override]
    public function acknowledge(string $jobId): void
    {
        if (!isset($this->receiptHandles[$jobId], $this->jobQueueUrls[$jobId])) {
            return;
        }

        $this->client()->deleteMessage([
            'QueueUrl' => $this->jobQueueUrls[$jobId],
            'ReceiptHandle' => $this->receiptHandles[$jobId],
        ]);

        unset(
            $this->receiptHandles[$jobId],
            $this->jobQueueUrls[$jobId],
            $this->knownJobs[$jobId],
        );
    }

    #[Override]
    public function reject(string $jobId, string $reason): void
    {
        if (!isset($this->receiptHandles[$jobId], $this->jobQueueUrls[$jobId])) {
            return;
        }

        $this->client()->changeMessageVisibility([
            'QueueUrl' => $this->jobQueueUrls[$jobId],
            'ReceiptHandle' => $this->receiptHandles[$jobId],
            'VisibilityTimeout' => 0,
        ]);

        if (isset($this->knownJobs[$jobId])) {
            $existing = $this->knownJobs[$jobId];

            $this->knownJobs[$jobId] = new JobRecord(
                id: $existing->id,
                queue: $existing->queue,
                jobClass: $existing->jobClass,
                payload: $existing->payload,
                attempts: $existing->attempts,
                status: JobRecordStatus::Failed,
                createdAt: $existing->createdAt,
                availableAt: $existing->availableAt,
            );
        }

        unset($this->receiptHandles[$jobId], $this->jobQueueUrls[$jobId]);
    }

    #[Override]
    public function size(string $queue): int
    {
        $result = $this->client()->getQueueAttributes([
            'QueueUrl' => $this->queueUrl($queue),
            'AttributeNames' => ['ApproximateNumberOfMessages'],
        ]);

        /** @var array<string, string> $attributes */
        $attributes = $result->get('Attributes') ?? [];

        return (int) ($attributes['ApproximateNumberOfMessages'] ?? 0);
    }

    #[Override]
    public function purge(string $queue): int
    {
        $queueUrl = $this->queueUrl($queue);

        $size = $this->size($queue);

        $this->client()->purgeQueue([
            'QueueUrl' => $queueUrl,
        ]);

        return $size;
    }

    /**
     * @return list<JobRecord>
     */
    #[Override]
    public function findByStatus(JobRecordStatus $status): array
    {
        $results = [];

        foreach ($this->knownJobs as $record) {
            if ($record->status === $status) {
                $results[] = $record;
            }
        }

        return $results;
    }

    private function client(): SqsClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        /** @var array<string, mixed> $config */
        $config = [
            'region' => $this->config->region,
            'version' => 'latest',
        ];

        if ($this->config->key !== '' && $this->config->secret !== '') {
            $config['credentials'] = [
                'key' => $this->config->key,
                'secret' => $this->config->secret,
            ];
        }

        $this->client = new SqsClient($config);

        return $this->client;
    }

    private function queueUrl(string $queue): string
    {
        if ($this->config->prefix !== '') {
            return rtrim($this->config->prefix, '/') . '/' . $queue;
        }

        return $queue;
    }
}
