<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\LiveCss;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Internal\LiveCss\LiveCssService;
use Pulsar\Extension\Cms\LiveCss\CspHashComputerInterface;
use Pulsar\Extension\Cms\LiveCss\CssOverride;
use Pulsar\Extension\Cms\LiveCss\CssOverrideRepositoryInterface;
use Pulsar\Extension\Cms\LiveCss\CssValidationResult;
use Pulsar\Extension\Cms\LiveCss\CssValidatorInterface;

#[CoversClass(LiveCssService::class)]
final class LiveCssServiceImplTest extends TestCase
{
    private CssOverrideRepositoryInterface & Stub $repo;
    private CssValidatorInterface & Stub $validator;
    private CspHashComputerInterface & Stub $hashComputer;

    protected function setUp(): void
    {
        $this->repo = $this->createStub(CssOverrideRepositoryInterface::class);
        $this->validator = $this->createStub(CssValidatorInterface::class);
        $this->hashComputer = $this->createStub(CspHashComputerInterface::class);
    }

    #[Test]
    public function getCurrentOverridesDelegatesToRepository(): void
    {
        $override = $this->buildOverride('theme-1');
        $this->repo->method('findActive')->willReturn($override);

        $service = new LiveCssService($this->repo, $this->validator, $this->hashComputer, null);
        $result = $service->getCurrentOverrides('theme-1');

        self::assertSame($override, $result);
    }

    #[Test]
    public function getCurrentOverridesReturnsNullWhenNone(): void
    {
        $this->repo->method('findActive')->willReturn(null);

        $service = new LiveCssService($this->repo, $this->validator, $this->hashComputer, null);

        self::assertNull($service->getCurrentOverrides('theme-1'));
    }

    #[Test]
    public function saveOverridesValidatesAndPersists(): void
    {
        $this->validator->method('validate')->willReturn(
            new CssValidationResult(isValid: true, errors: [], sanitizedCss: 'body { color: red; }'),
        );
        $this->hashComputer->method('computeHash')->willReturn('hash123');

        $repo = $this->createMock(CssOverrideRepositoryInterface::class);
        $repo->method('getNextVersion')->willReturn(2);
        $repo->expects(self::once())->method('deactivateAll')->with('theme-1', null);
        $repo->expects(self::once())->method('save');

        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $service = new LiveCssService($repo, $this->validator, $this->hashComputer, $auditLogger);
        $override = $service->saveOverrides('theme-1', 'body { color: red; }', ['primary' => '#ff0000'], 'Brand update', 'user-1');

        self::assertSame('theme-1', $override->themeId);
        self::assertSame(2, $override->version);
        self::assertTrue($override->isActive);
        self::assertSame('body { color: red; }', $override->cssContent);
        self::assertSame('hash123', $override->cssHash);
    }

    #[Test]
    public function saveOverridesThrowsOnInvalidCss(): void
    {
        $this->validator->method('validate')->willReturn(
            new CssValidationResult(isValid: false, errors: ['Contains url()'], sanitizedCss: ''),
        );

        $service = new LiveCssService($this->repo, $this->validator, $this->hashComputer, null);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('CSS validation failed');

        $service->saveOverrides('theme-1', 'body { background: url(evil); }', [], 'Test', 'user-1');
    }

    #[Test]
    public function rollbackCreatesNewVersionFromTarget(): void
    {
        $target = $this->buildOverride('theme-1', version: 1);

        $repo = $this->createMock(CssOverrideRepositoryInterface::class);
        $repo->method('findById')->willReturn($target);
        $repo->method('getNextVersion')->willReturn(3);
        $repo->expects(self::once())->method('deactivateAll');
        $repo->expects(self::once())->method('save');

        $service = new LiveCssService($repo, $this->validator, $this->hashComputer, null);
        $override = $service->rollback('override-1', 'Rollback reason', 'admin-1');

        self::assertSame('theme-1', $override->themeId);
        self::assertSame(3, $override->version);
        self::assertTrue($override->isActive);
        self::assertSame($target->cssContent, $override->cssContent);
    }

    #[Test]
    public function rollbackThrowsWhenOverrideNotFound(): void
    {
        $this->repo->method('findById')->willReturn(null);

        $service = new LiveCssService($this->repo, $this->validator, $this->hashComputer, null);

        $this->expectException(CmsException::class);
        $service->rollback('nonexistent', 'reason', 'user-1');
    }

    #[Test]
    public function getVersionHistoryDelegatesToRepository(): void
    {
        $this->repo->method('getHistory')->willReturn([
            $this->buildOverride('theme-1', version: 1),
            $this->buildOverride('theme-1', version: 2),
        ]);

        $service = new LiveCssService($this->repo, $this->validator, $this->hashComputer, null);
        $history = $service->getVersionHistory('theme-1', null);

        self::assertCount(2, $history);
    }

    #[Test]
    public function saveOverridesWorksWithoutAuditLogger(): void
    {
        $this->validator->method('validate')->willReturn(
            new CssValidationResult(isValid: true, errors: [], sanitizedCss: 'body {}'),
        );
        $this->hashComputer->method('computeHash')->willReturn('h');
        $this->repo->method('getNextVersion')->willReturn(1);

        $service = new LiveCssService($this->repo, $this->validator, $this->hashComputer, null);
        $override = $service->saveOverrides('theme-1', 'body {}', [], 'Test', 'user-1');

        self::assertSame(1, $override->version);
    }

    private function buildOverride(string $themeId, int $version = 1): CssOverride
    {
        return new CssOverride(
            id: 'override-' . $version,
            tenantId: null,
            themeId: $themeId,
            version: $version,
            cssContent: 'body { color: blue; }',
            cssHash: 'hash-' . $version,
            tokenOverrides: [],
            isActive: $version === 1,
            createdAt: new DateTimeImmutable(),
            createdBy: 'user-1',
            reason: 'Initial',
        );
    }
}
