<?php

declare(strict_types=1);

namespace Pulsar\Cloud\Aws;

use Pulsar\Api\Internal;
use Pulsar\Cloud\Aws\Config\AwsConfig;
use Pulsar\Cloud\CloudException;
use Pulsar\Cloud\CloudHttpClient;
use Pulsar\Observability\Metrics\MetricSnapshot;
use Pulsar\Observability\Metrics\MetricType;

use function count;
use function gmdate;
use function hash;
use function http_build_query;
use function sprintf;
use function str_replace;
use function time;

/**
 * CloudWatch metric exporter using raw HTTP with SigV4 signing.
 *
 * Buffers metric snapshots and flushes them to CloudWatch in batches.
 * Supports counters, gauges, and histograms mapped to CloudWatch
 * metric data with appropriate units and dimensions.
 */
#[Internal]
final class CloudWatchMetricExporter
{
    private const int MAX_BATCH_SIZE = 20;

    /** @var list<MetricSnapshot> */
    private array $buffer = [];

    private ?AwsSigner $signer = null;

    public function __construct(
        private readonly AwsConfig $config,
        private readonly string $namespace = 'Pulsar',
        private readonly CloudHttpClient $httpClient = new CloudHttpClient(),
    ) {}

    /**
     * Export a single metric snapshot (buffers for batch delivery).
     */
    public function export(MetricSnapshot $snapshot): void
    {
        $this->buffer[] = $snapshot;

        if (count($this->buffer) >= self::MAX_BATCH_SIZE) {
            $this->flush();
        }
    }

    /**
     * Flush all buffered snapshots to CloudWatch.
     */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $params = [
            'Action' => 'PutMetricData',
            'Namespace' => $this->namespace,
            'Version' => '2010-08-01',
        ];

        $metricIndex = 1;
        foreach ($this->buffer as $snapshot) {
            foreach ($snapshot->values as $labelKey => $value) {
                $params[sprintf('MetricData.member.%d.MetricName', $metricIndex)] = $snapshot->name;
                $params[sprintf('MetricData.member.%d.Value', $metricIndex)] = (string) $value;
                $params[sprintf('MetricData.member.%d.Timestamp', $metricIndex)] = gmdate('Y-m-d\TH:i:s\Z', time());
                $params[sprintf('MetricData.member.%d.Unit', $metricIndex)] = $this->mapUnit($snapshot->type);

                if ($labelKey !== '') {
                    $params[sprintf('MetricData.member.%d.Dimensions.member.1.Name', $metricIndex)] = 'Label';
                    $params[sprintf('MetricData.member.%d.Dimensions.member.1.Value', $metricIndex)] = $labelKey;
                }

                $metricIndex++;
            }
        }

        $this->cloudWatchRequest($params);
        $this->buffer = [];
    }

    /**
     * Shut down the exporter, flushing remaining data.
     */
    public function shutdown(): void
    {
        $this->flush();
    }

    /**
     * @param array<string, string> $params CloudWatch API parameters
     */
    private function cloudWatchRequest(array $params): void
    {
        $endpoint = $this->config->endpoint
            ?? sprintf('https://monitoring.%s.amazonaws.com', $this->config->region);

        $body = http_build_query($params);
        $payloadHash = hash('sha256', $body);
        $host = str_replace(['https://', 'http://'], '', $endpoint);

        $headers = [
            'Host' => $host,
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];

        $signer = $this->getSigner();
        $signedHeaders = $signer->sign('POST', '/', '', $headers, $payloadHash);

        try {
            $response = $this->httpClient->request('POST', $endpoint, $signedHeaders, $body);
        } catch (CloudException $e) {
            throw CloudException::requestFailed('cloudwatch', $e->getMessage(), $e);
        }

        if ($response->statusCode >= 400) {
            throw CloudException::requestFailed(
                'cloudwatch',
                sprintf('HTTP %d: %s', $response->statusCode, $response->body),
            );
        }
    }

    private function mapUnit(MetricType $type): string
    {
        return match ($type) {
            MetricType::Counter => 'Count',
            MetricType::Gauge => 'None',
            MetricType::Histogram => 'Milliseconds',
        };
    }

    private function getSigner(): AwsSigner
    {
        if ($this->signer !== null) {
            return $this->signer;
        }

        $credentials = $this->config->resolveCredentials();

        if ($credentials['access_key'] === '' || $credentials['secret_key'] === '') {
            throw CloudException::authenticationFailed('aws', 'AWS credentials not configured for CloudWatch');
        }

        $this->signer = new AwsSigner(
            $credentials['access_key'],
            $credentials['secret_key'],
            $this->config->region,
            'monitoring',
        );

        return $this->signer;
    }
}
