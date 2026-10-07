<?php

declare(strict_types=1);

namespace Pulsar\Cloud\Aws;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Cloud\Aws\Config\AwsConfig;
use Pulsar\Cloud\CloudException;
use Pulsar\Cloud\CloudHttpClient;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueDriverInterface;
use Random\Engine\Secure;
use Random\Randomizer;

use function bin2hex;
use function hash;
use function htmlspecialchars_decode;
use function http_build_query;
use function json_decode;
use function json_encode;
use function min;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_replace;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * Amazon SQS queue driver using raw HTTP with AWS SigV4 signing.
 *
 * Supports standard and FIFO queues, delayed messages, and dead letter
 * queue configuration. Uses the SQS JSON (Query) API directly without
 * the AWS SDK.
 */
#[Internal(reason: 'Implementation detail; use QueueDriverInterface contract')]
final class SqsQueueDriver implements QueueDriverInterface
{
    private const int MAX_SQS_DELAY_SECONDS = 900;

    private readonly Randomizer $randomizer;
    private ?AwsSigner $signer = null;

    /** @var array<string, string> Receipt handle indexed by job ID */
    private array $receiptHandles = [];

    /** @var array<string, string> Queue URL indexed by job ID */
    private array $jobQueueUrls = [];

    /** @var array<string, JobRecord> In-memory tracking for findByStatus */
    private array $knownJobs = [];

    public function __construct(
        private readonly AwsConfig $config,
        private readonly string $queuePrefix = '',
        private readonly bool $fifo = false,
        private readonly CloudHttpClient $httpClient = new CloudHttpClient(),
        ?Randomizer $randomizer = null,
    ) {
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

        $params = [
            'Action' => 'SendMessage',
            'QueueUrl' => $this->queueUrl($queue),
            'MessageBody' => $jobData,
        ];

        if ($delay > 0) {
            $params['DelaySeconds'] = (string) min($delay, self::MAX_SQS_DELAY_SECONDS);
        }

        if ($this->fifo) {
            $params['MessageGroupId'] = $queue;
            $params['MessageDeduplicationId'] = $id;
        }

        $this->sqsRequest($params);

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

        $params = [
            'Action' => 'ReceiveMessage',
            'QueueUrl' => $queueUrl,
            'MaxNumberOfMessages' => '1',
            'WaitTimeSeconds' => '0',
            'AttributeName.1' => 'ApproximateReceiveCount',
        ];

        $response = $this->sqsRequest($params);

        // Parse the XML response for message data
        if (preg_match('/<Body>(.*?)<\/Body>/s', $response, $bodyMatch) !== 1) {
            return null;
        }

        $body = htmlspecialchars_decode($bodyMatch[1]);

        $receiptHandle = '';
        if (preg_match('/<ReceiptHandle>(.*?)<\/ReceiptHandle>/s', $response, $rhMatch) === 1) {
            $receiptHandle = $rhMatch[1];
        }

        /** @var array{id: string, queue: string, job_class: string, payload: string, attempts: int, status: string, created_at: int, available_at: int} $data */
        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        $receiveCount = $data['attempts'] + 1;

        // Try to extract ApproximateReceiveCount from attributes
        if (preg_match('/<Name>ApproximateReceiveCount<\/Name>\s*<Value>(\d+)<\/Value>/s', $response, $countMatch) === 1) {
            $receiveCount = (int) $countMatch[1];
        }

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

        $this->sqsRequest([
            'Action' => 'DeleteMessage',
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

        $this->sqsRequest([
            'Action' => 'ChangeMessageVisibility',
            'QueueUrl' => $this->jobQueueUrls[$jobId],
            'ReceiptHandle' => $this->receiptHandles[$jobId],
            'VisibilityTimeout' => '0',
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
        $response = $this->sqsRequest([
            'Action' => 'GetQueueAttributes',
            'QueueUrl' => $this->queueUrl($queue),
            'AttributeName.1' => 'ApproximateNumberOfMessages',
        ]);

        if (preg_match('/<Value>(\d+)<\/Value>/', $response, $match) === 1) {
            return (int) $match[1];
        }

        return 0;
    }

    #[Override]
    public function purge(string $queue): int
    {
        $size = $this->size($queue);

        $this->sqsRequest([
            'Action' => 'PurgeQueue',
            'QueueUrl' => $this->queueUrl($queue),
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

    /**
     * @param array<string, string> $params SQS API parameters
     * @return string Raw response body
     */
    private function sqsRequest(array $params): string
    {
        $endpoint = $this->config->endpoint
            ?? sprintf('https://sqs.%s.amazonaws.com', $this->config->region);

        $body = http_build_query($params);
        $payloadHash = hash('sha256', $body);
        $host = str_replace(['https://', 'http://'], '', $endpoint);

        $headers = [
            'Host' => $host,
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];

        $signedHeaders = $this->getSigner()->sign('POST', '/', '', $headers, $payloadHash);

        try {
            $response = $this->httpClient->request('POST', $endpoint, $signedHeaders, $body);
        } catch (CloudException $e) {
            throw CloudException::requestFailed('sqs', $e->getMessage(), $e);
        }

        if ($response->statusCode >= 400) {
            throw CloudException::requestFailed('sqs', sprintf('HTTP %d: %s', $response->statusCode, $response->body));
        }

        return $response->body;
    }

    private function queueUrl(string $queue): string
    {
        $suffix = $this->fifo ? '.fifo' : '';

        if ($this->queuePrefix !== '') {
            return rtrim($this->queuePrefix, '/') . '/' . $queue . $suffix;
        }

        return $queue . $suffix;
    }

    private function getSigner(): AwsSigner
    {
        if ($this->signer !== null) {
            return $this->signer;
        }

        $credentials = $this->config->resolveCredentials();

        if ($credentials['access_key'] === '' || $credentials['secret_key'] === '') {
            throw CloudException::authenticationFailed('aws', 'AWS credentials not configured for SQS');
        }

        $this->signer = new AwsSigner(
            $credentials['access_key'],
            $credentials['secret_key'],
            $this->config->region,
            'sqs',
        );

        return $this->signer;
    }
}
