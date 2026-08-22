<?php

declare(strict_types=1);

namespace Pulsar\Cloud\Aws;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Cloud\Aws\Config\AwsConfig;
use Pulsar\Cloud\CloudException;
use Pulsar\Cloud\CloudHttpClient;
use Pulsar\Notification\Exception\NotificationException;
use Pulsar\Notification\NotifiableInterface;
use Pulsar\Notification\Notification;
use Pulsar\Notification\NotificationChannelInterface;
use Throwable;

use function hash;
use function http_build_query;
use function is_string;
use function json_encode;
use function sprintf;
use function str_replace;

use const JSON_THROW_ON_ERROR;

/**
 * AWS SNS notification channel for push notifications.
 *
 * Delivers notifications to SNS topics or target ARNs using the
 * SNS API directly with SigV4 signing. Supports both topic-based
 * and direct endpoint publishing.
 */
#[Internal]
final readonly class SnsNotificationChannel implements NotificationChannelInterface
{
    public function __construct(
        private AwsConfig $config,
        private CloudHttpClient $httpClient = new CloudHttpClient(),
    ) {}

    #[Override]
    public function send(NotifiableInterface $notifiable, Notification $notification): void
    {
        $targetArn = $notifiable->routeNotificationFor($this->name());

        if (!is_string($targetArn) || $targetArn === '') {
            throw NotificationException::channelNotAvailable(
                $this->name(),
                'No SNS target ARN configured for notifiable ' . $notifiable->getNotifiableId(),
            );
        }

        $webhookPayload = $notification->toWebhook($notifiable);

        $message = json_encode($webhookPayload->data, JSON_THROW_ON_ERROR);

        try {
            $this->publish($targetArn, $message);
        } catch (CloudException $e) {
            throw NotificationException::deliveryFailed(
                $this->name(),
                $notifiable->getNotifiableId(),
                $e,
            );
        } catch (NotificationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw NotificationException::deliveryFailed(
                $this->name(),
                $notifiable->getNotifiableId(),
                $e,
            );
        }
    }

    #[Override]
    public function name(): string
    {
        return 'sns';
    }

    /**
     * Publish a message to an SNS topic or target ARN.
     */
    private function publish(string $targetArn, string $message): void
    {
        $endpoint = $this->config->endpoint
            ?? sprintf('https://sns.%s.amazonaws.com', $this->config->region);

        $params = [
            'Action' => 'Publish',
            'TargetArn' => $targetArn,
            'Message' => $message,
        ];

        $body = http_build_query($params);
        $payloadHash = hash('sha256', $body);
        $host = str_replace(['https://', 'http://'], '', $endpoint);

        $headers = [
            'Host' => $host,
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];

        $signer = $this->getSigner();
        $signedHeaders = $signer->sign('POST', '/', '', $headers, $payloadHash);

        $response = $this->httpClient->request('POST', $endpoint, $signedHeaders, $body);

        if ($response->statusCode >= 400) {
            throw CloudException::requestFailed('sns', sprintf('HTTP %d: %s', $response->statusCode, $response->body));
        }
    }

    private function getSigner(): AwsSigner
    {
        $credentials = $this->config->resolveCredentials();

        if ($credentials['access_key'] === '' || $credentials['secret_key'] === '') {
            throw CloudException::authenticationFailed('aws', 'AWS credentials not configured for SNS');
        }

        return new AwsSigner(
            $credentials['access_key'],
            $credentials['secret_key'],
            $this->config->region,
            'sns',
        );
    }
}
