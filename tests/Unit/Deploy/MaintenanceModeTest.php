<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Deploy;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Deploy\MaintenanceMode;

#[CoversClass(MaintenanceMode::class)]
final class MaintenanceModeTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_maint_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $maintenanceFile = $this->tempDir . DIRECTORY_SEPARATOR . 'maintenance.json';

        if (is_file($maintenanceFile)) {
            @unlink($maintenanceFile); // nosemgrep: php.lang.security.unlink-use.unlink-use
        }

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    #[Test]
    public function is_not_active_by_default(): void
    {
        $mode = new MaintenanceMode($this->tempDir);

        self::assertFalse($mode->isActive());
    }

    #[Test]
    public function enable_activates_maintenance_mode(): void
    {
        $mode = new MaintenanceMode($this->tempDir);

        $mode->enable();

        self::assertTrue($mode->isActive());
    }

    #[Test]
    public function disable_deactivates_maintenance_mode(): void
    {
        $mode = new MaintenanceMode($this->tempDir);

        $mode->enable();
        self::assertTrue($mode->isActive());

        $mode->disable();
        self::assertFalse($mode->isActive());
    }

    #[Test]
    public function disable_is_safe_when_not_active(): void
    {
        $mode = new MaintenanceMode($this->tempDir);

        $mode->disable();

        self::assertFalse($mode->isActive());
    }

    #[Test]
    public function payload_returns_null_when_not_active(): void
    {
        $mode = new MaintenanceMode($this->tempDir);

        self::assertNull($mode->payload());
    }

    #[Test]
    public function payload_returns_maintenance_data(): void
    {
        $mode = new MaintenanceMode($this->tempDir);

        $mode->enable(
            secret: 'bypass-secret',
            message: 'Upgrading...',
            retryAfter: 120,
            allowedIps: ['127.0.0.1'],
        );

        $payload = $mode->payload();

        self::assertNotNull($payload);
        self::assertTrue($payload['enabled']);
        self::assertSame('bypass-secret', $payload['secret']);
        self::assertSame('Upgrading...', $payload['message']);
        self::assertSame(120, $payload['retry_after']);
        self::assertSame(['127.0.0.1'], $payload['allowed_ips']);
        self::assertIsInt($payload['time']);
    }

    #[Test]
    public function default_message_is_set_when_none_provided(): void
    {
        $mode = new MaintenanceMode($this->tempDir);

        $mode->enable();

        $payload = $mode->payload();

        self::assertNotNull($payload);
        self::assertStringContainsString('maintenance', $payload['message']);
    }

    #[Test]
    public function check_secret_returns_true_for_matching_secret(): void
    {
        $mode = new MaintenanceMode($this->tempDir);

        $mode->enable(secret: 'my-secret');

        self::assertTrue($mode->checkSecret('my-secret'));
    }

    #[Test]
    public function check_secret_returns_false_for_wrong_secret(): void
    {
        $mode = new MaintenanceMode($this->tempDir);

        $mode->enable(secret: 'my-secret');

        self::assertFalse($mode->checkSecret('wrong-secret'));
    }

    #[Test]
    public function check_secret_returns_false_when_no_secret_set(): void
    {
        $mode = new MaintenanceMode($this->tempDir);

        $mode->enable();

        self::assertFalse($mode->checkSecret('anything'));
    }

    #[Test]
    public function check_secret_returns_false_when_not_active(): void
    {
        $mode = new MaintenanceMode($this->tempDir);

        self::assertFalse($mode->checkSecret('anything'));
    }

    #[Test]
    public function is_ip_allowed_returns_true_for_allowed_ip(): void
    {
        $mode = new MaintenanceMode($this->tempDir);

        $mode->enable(allowedIps: ['10.0.0.1', '192.168.1.1']);

        self::assertTrue($mode->isIpAllowed('10.0.0.1'));
        self::assertTrue($mode->isIpAllowed('192.168.1.1'));
    }

    #[Test]
    public function is_ip_allowed_returns_false_for_unlisted_ip(): void
    {
        $mode = new MaintenanceMode($this->tempDir);

        $mode->enable(allowedIps: ['10.0.0.1']);

        self::assertFalse($mode->isIpAllowed('10.0.0.2'));
    }

    #[Test]
    public function is_ip_allowed_returns_false_when_not_active(): void
    {
        $mode = new MaintenanceMode($this->tempDir);

        self::assertFalse($mode->isIpAllowed('127.0.0.1'));
    }

    #[Test]
    public function constructor_rejects_path_with_traversal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('traversal');

        new MaintenanceMode('/some/../path');
    }

    #[Test]
    public function constructor_rejects_nonexistent_directory(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MaintenanceMode('/nonexistent/path/that/does/not/exist');
    }
}
