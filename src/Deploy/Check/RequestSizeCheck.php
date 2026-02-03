<?php

declare(strict_types=1);

namespace Pulsar\Deploy\Check;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Config\DeployConfig;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\DeployCheckInterface;

use function sprintf;

/**
 * Validates POST body and upload size limits are reasonable.
 *
 * Unreasonably large limits can expose the application to denial-of-service
 * attacks through resource exhaustion.
 */
#[Internal]
final readonly class RequestSizeCheck implements DeployCheckInterface
{
    private const string CHECK_NAME = 'request-size-limits';

    /** Maximum recommended POST size in MB before triggering a warning. */
    private const int MAX_RECOMMENDED_POST_MB = 100;

    /** Maximum recommended upload size in MB before triggering a warning. */
    private const int MAX_RECOMMENDED_UPLOAD_MB = 100;

    public function __construct(
        private DeployConfig $deployConfig,
    ) {}

    #[Override]
    public function getName(): string
    {
        return self::CHECK_NAME;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Validates POST body and upload size limits are reasonable';
    }

    #[Override]
    public function check(string $environment): CheckResult
    {
        $postSize = $this->deployConfig->maxPostSizeMb;
        $uploadSize = $this->deployConfig->maxUploadSizeMb;

        $issues = [];
        $recommendations = [];

        if ($postSize > self::MAX_RECOMMENDED_POST_MB) {
            $issues[] = sprintf('max POST size is %dMB (recommended <= %dMB)', $postSize, self::MAX_RECOMMENDED_POST_MB);
            $recommendations[] = sprintf(
                'Reduce max_post_size_mb to %dMB or less in config/deploy.php.',
                self::MAX_RECOMMENDED_POST_MB,
            );
        }

        if ($uploadSize > self::MAX_RECOMMENDED_UPLOAD_MB) {
            $issues[] = sprintf('max upload size is %dMB (recommended <= %dMB)', $uploadSize, self::MAX_RECOMMENDED_UPLOAD_MB);
            $recommendations[] = sprintf(
                'Reduce max_upload_size_mb to %dMB or less in config/deploy.php.',
                self::MAX_RECOMMENDED_UPLOAD_MB,
            );
        }

        if ($issues === []) {
            return CheckResult::pass(
                self::CHECK_NAME,
                sprintf('Request size limits are reasonable (POST: %dMB, Upload: %dMB)', $postSize, $uploadSize),
            );
        }

        $message = implode('; ', $issues);

        $recommendations[] = 'Large request limits increase the risk of denial-of-service through resource exhaustion.';

        return match ($environment) {
            'production', 'staging' => CheckResult::warning(
                self::CHECK_NAME,
                $message,
                $recommendations,
            ),
            default => CheckResult::pass(
                self::CHECK_NAME,
                'Request size limits not enforced in local environment',
            ),
        };
    }
}
