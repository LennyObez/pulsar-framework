<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Audit\AccessibilityAuditor;
use Pulsar\Extension\Accessibility\Audit\ManualChecklistGenerator;
use Pulsar\Extension\Accessibility\Command\AccessibilityAuditCommand;
use Pulsar\Extension\Accessibility\Validator\HeadingHierarchyValidator;

final class AccessibilityAuditCommandTest extends TestCase
{
    private AccessibilityAuditCommand $command;

    protected function setUp(): void
    {
        $auditor = new AccessibilityAuditor([
            new HeadingHierarchyValidator(),
        ]);

        $this->command = new AccessibilityAuditCommand(
            $auditor,
            new ManualChecklistGenerator(),
        );
    }

    #[Test]
    public function command_name_is_a11y_audit(): void
    {
        self::assertSame('a11y:audit', $this->command->name);
    }

    #[Test]
    public function has_required_path_argument(): void
    {
        $pathArg = null;

        foreach ($this->command->arguments as $arg) {
            if ($arg['name'] === 'path') {
                $pathArg = $arg;
                break;
            }
        }

        self::assertNotNull($pathArg, 'Command should have a "path" argument.');
        self::assertTrue($pathArg['required'], 'The "path" argument should be required.');
    }

    #[Test]
    public function has_format_option(): void
    {
        self::assertArrayHasKey('format', $this->command->options);
        self::assertSame('f', $this->command->options['format']['shortcut']);
    }

    #[Test]
    public function has_browser_option(): void
    {
        self::assertArrayHasKey('browser', $this->command->options);
    }

    #[Test]
    public function has_severity_option(): void
    {
        self::assertArrayHasKey('severity', $this->command->options);
        self::assertSame('s', $this->command->options['severity']['shortcut']);
    }

    #[Test]
    public function has_pattern_option(): void
    {
        self::assertArrayHasKey('pattern', $this->command->options);
        self::assertSame('p', $this->command->options['pattern']['shortcut']);
    }

    #[Test]
    public function command_has_description(): void
    {
        self::assertNotEmpty($this->command->description);
    }
}
