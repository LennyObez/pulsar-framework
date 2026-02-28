<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime\Scope;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Runtime\Exception\StatefulSingletonException;
use Pulsar\Runtime\Scope\StatefulSingletonAnalyzer;
use Pulsar\Runtime\Scope\StatefulSingletonViolation;
use Pulsar\Runtime\Scope\ViolationType;
use Pulsar\Tests\Unit\Runtime\Scope\Fixtures\ImmutableService;
use Pulsar\Tests\Unit\Runtime\Scope\Fixtures\MutableService;
use Pulsar\Tests\Unit\Runtime\Scope\Fixtures\ReadonlyPropertiesService;
use Pulsar\Tests\Unit\Runtime\Scope\Fixtures\ResettableRequestStateService;
use Pulsar\Tests\Unit\Runtime\Scope\Fixtures\ResettableService;
use Pulsar\Tests\Unit\Runtime\Scope\Fixtures\StaticMutableService;

#[CoversClass(StatefulSingletonAnalyzer::class)]
final class StatefulSingletonAnalyzerTest extends TestCase
{
    #[Test]
    public function analyze_returns_empty_for_readonly_class(): void
    {
        $analyzer = new StatefulSingletonAnalyzer();

        $violations = $analyzer->analyze(ImmutableService::class);

        self::assertSame([], $violations);
    }

    #[Test]
    public function analyze_detects_writable_properties(): void
    {
        $analyzer = new StatefulSingletonAnalyzer();

        $violations = $analyzer->analyze(MutableService::class);

        $writableViolations = array_filter(
            $violations,
            static fn($v) => $v->type === ViolationType::WritableProperty,
        );

        self::assertCount(2, $writableViolations);

        $properties = array_map(static fn($v) => $v->property, array_values($writableViolations));
        self::assertContains('state', $properties);
        self::assertContains('counter', $properties);
    }

    #[Test]
    public function analyze_detects_mutable_static_properties(): void
    {
        $analyzer = new StatefulSingletonAnalyzer();

        $violations = $analyzer->analyze(StaticMutableService::class);

        $staticViolations = array_filter(
            $violations,
            static fn($v) => $v->type === ViolationType::MutableStatic,
        );

        self::assertCount(1, $staticViolations);

        $violation = array_values($staticViolations)[0];
        self::assertSame('callCount', $violation->property);
        self::assertSame(StaticMutableService::class, $violation->className);
        self::assertStringContainsString('mutable static property', $violation->message);
    }

    #[Test]
    public function analyze_detects_reset_method(): void
    {
        $analyzer = new StatefulSingletonAnalyzer();

        $violations = $analyzer->analyze(ResettableService::class);

        $resetViolations = array_filter(
            $violations,
            static fn($v) => $v->type === ViolationType::ResetMethod,
        );

        self::assertCount(1, $resetViolations);

        $violation = array_values($resetViolations)[0];
        self::assertSame('reset()', $violation->property);
        self::assertStringContainsString('reset()', $violation->message);
    }

    #[Test]
    public function analyze_detects_reset_request_state_method(): void
    {
        $analyzer = new StatefulSingletonAnalyzer();

        $violations = $analyzer->analyze(ResettableRequestStateService::class);

        $resetViolations = array_filter(
            $violations,
            static fn($v) => $v->type === ViolationType::ResetMethod,
        );

        self::assertCount(1, $resetViolations);

        $violation = array_values($resetViolations)[0];
        self::assertSame('resetRequestState()', $violation->property);
        self::assertStringContainsString('resetRequestState()', $violation->message);
    }

    #[Test]
    public function analyze_ignores_readonly_properties(): void
    {
        $analyzer = new StatefulSingletonAnalyzer();

        $violations = $analyzer->analyze(ReadonlyPropertiesService::class);

        $writableViolations = array_filter(
            $violations,
            static fn($v) => $v->type === ViolationType::WritableProperty,
        );

        self::assertCount(0, $writableViolations);
    }

    #[Test]
    public function is_core_namespace_returns_true_for_pulsar_prefix(): void
    {
        $analyzer = new StatefulSingletonAnalyzer();

        self::assertTrue($analyzer->isCoreNamespace('Pulsar\\Runtime\\SomeClass'));
        self::assertTrue($analyzer->isCoreNamespace('Pulsar\\Core\\Service'));
    }

    #[Test]
    public function is_core_namespace_returns_false_for_non_core_prefix(): void
    {
        $analyzer = new StatefulSingletonAnalyzer();

        self::assertFalse($analyzer->isCoreNamespace('App\\Service\\UserService'));
        self::assertFalse($analyzer->isCoreNamespace('Vendor\\Package\\Class'));
    }

    #[Test]
    public function report_does_nothing_for_empty_violations(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $analyzer = new StatefulSingletonAnalyzer(strict: true, logger: $logger);

        $analyzer->report([]);
    }

    #[Test]
    public function report_logs_warnings_for_non_core_violations(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $analyzer = new StatefulSingletonAnalyzer(strict: true, logger: $logger);

        $violation = new StatefulSingletonViolation(
            className: 'App\\Service\\UserCache',
            property: 'cache',
            type: ViolationType::WritableProperty,
            message: 'Writable property detected',
        );

        $analyzer->report([$violation]);
    }

    #[Test]
    public function report_throws_in_strict_mode_for_core_violations(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $analyzer = new StatefulSingletonAnalyzer(strict: true, logger: $logger);

        $violation = new StatefulSingletonViolation(
            className: 'Pulsar\\Runtime\\SomeService',
            property: 'state',
            type: ViolationType::WritableProperty,
            message: 'Writable property in core',
        );

        $this->expectException(StatefulSingletonException::class);
        $this->expectExceptionMessage('stateful singleton violation');

        $analyzer->report([$violation]);
    }

    #[Test]
    public function report_does_not_throw_for_non_core_violations_in_strict_mode(): void
    {
        $analyzer = new StatefulSingletonAnalyzer(strict: true);

        $violation = new StatefulSingletonViolation(
            className: 'App\\External\\Service',
            property: 'data',
            type: ViolationType::WritableProperty,
            message: 'Non-core writable property',
        );

        $analyzer->report([$violation]);

        // Non-core violations must not throw even in strict mode
        self::assertFalse($analyzer->isCoreNamespace('App\\External\\Service'));
    }

    #[Test]
    public function report_does_not_throw_in_non_strict_mode_for_core_violations(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $analyzer = new StatefulSingletonAnalyzer(strict: false, logger: $logger);

        $violation = new StatefulSingletonViolation(
            className: 'Pulsar\\Runtime\\SomeService',
            property: 'state',
            type: ViolationType::WritableProperty,
            message: 'Core violation but not strict',
        );

        $analyzer->report([$violation]);
    }

    #[Test]
    public function custom_core_namespaces(): void
    {
        $analyzer = new StatefulSingletonAnalyzer(
            strict: true,
            coreNamespaces: ['MyCompany\\Core\\'],
        );

        self::assertTrue($analyzer->isCoreNamespace('MyCompany\\Core\\Service'));
        self::assertFalse($analyzer->isCoreNamespace('Pulsar\\Something'));
    }
}
