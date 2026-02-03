<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Deploy\Check;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\DeployConfig;
use Pulsar\Deploy\Check\RequestSizeCheck;
use Pulsar\Deploy\CheckSeverity;

#[CoversClass(RequestSizeCheck::class)]
final class RequestSizeCheckTest extends TestCase
{
    #[Test]
    public function it_returns_name(): void
    {
        $check = new RequestSizeCheck(new DeployConfig());

        self::assertSame('request-size-limits', $check->getName());
    }

    #[Test]
    public function it_returns_description(): void
    {
        $check = new RequestSizeCheck(new DeployConfig());

        self::assertSame('Validates POST body and upload size limits are reasonable', $check->getDescription());
    }

    #[Test]
    public function it_passes_with_default_sizes(): void
    {
        $config = new DeployConfig(maxPostSizeMb: 8, maxUploadSizeMb: 10);
        $check = new RequestSizeCheck($config);

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertStringContainsString('POST: 8MB', $result->message);
        self::assertStringContainsString('Upload: 10MB', $result->message);
    }

    #[Test]
    public function it_passes_at_exactly_100mb(): void
    {
        $config = new DeployConfig(maxPostSizeMb: 100, maxUploadSizeMb: 100);
        $check = new RequestSizeCheck($config);

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
    }

    #[Test]
    public function it_warns_when_post_size_exceeds_limit_in_production(): void
    {
        $config = new DeployConfig(maxPostSizeMb: 200, maxUploadSizeMb: 10);
        $check = new RequestSizeCheck($config);

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('max POST size is 200MB', $result->message);
    }

    #[Test]
    public function it_warns_when_upload_size_exceeds_limit_in_production(): void
    {
        $config = new DeployConfig(maxPostSizeMb: 10, maxUploadSizeMb: 500);
        $check = new RequestSizeCheck($config);

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('max upload size is 500MB', $result->message);
    }

    #[Test]
    public function it_warns_when_both_sizes_exceed_limit_in_production(): void
    {
        $config = new DeployConfig(maxPostSizeMb: 150, maxUploadSizeMb: 200);
        $check = new RequestSizeCheck($config);

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('max POST size is 150MB', $result->message);
        self::assertStringContainsString('max upload size is 200MB', $result->message);
    }

    #[Test]
    public function it_warns_in_staging_for_oversized_limits(): void
    {
        $config = new DeployConfig(maxPostSizeMb: 200, maxUploadSizeMb: 200);
        $check = new RequestSizeCheck($config);

        $result = $check->check('staging');

        self::assertSame(CheckSeverity::Warning, $result->severity);
    }

    #[Test]
    public function it_passes_in_local_regardless_of_sizes(): void
    {
        $config = new DeployConfig(maxPostSizeMb: 999, maxUploadSizeMb: 999);
        $check = new RequestSizeCheck($config);

        $result = $check->check('local');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertStringContainsString('not enforced in local', $result->message);
    }

    #[Test]
    public function warning_recommendations_mention_dos_risk(): void
    {
        $config = new DeployConfig(maxPostSizeMb: 200, maxUploadSizeMb: 10);
        $check = new RequestSizeCheck($config);

        $result = $check->check('production');

        $joined = implode(' ', $result->recommendations);
        self::assertStringContainsString('denial-of-service', $joined);
    }

    #[Test]
    public function warning_recommendations_mention_config_file(): void
    {
        $config = new DeployConfig(maxPostSizeMb: 200, maxUploadSizeMb: 10);
        $check = new RequestSizeCheck($config);

        $result = $check->check('production');

        $joined = implode(' ', $result->recommendations);
        self::assertStringContainsString('config/deploy.php', $joined);
    }
}
