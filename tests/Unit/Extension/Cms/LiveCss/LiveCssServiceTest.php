<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\LiveCss;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\LiveCss\CspHashComputerInterface;
use Pulsar\Extension\Cms\LiveCss\CssOverride;
use Pulsar\Extension\Cms\LiveCss\CssOverrideRepositoryInterface;
use Pulsar\Extension\Cms\LiveCss\CssValidationResult;
use Pulsar\Extension\Cms\LiveCss\CssValidatorInterface;
use Pulsar\Extension\Cms\LiveCss\LiveCssServiceInterface;

use function array_slice;
use function array_values;
use function base64_encode;
use function hash;
use function usort;

#[CoversClass(CssOverride::class)]
final class LiveCssServiceTest extends TestCase
{
    // ── saveOverrides increments version ─────────────────────────────

    #[Test]
    public function saveOverridesIncrementsVersion(): void
    {
        $service = $this->createService();

        $v1 = $service->saveOverrides(
            themeId: 'theme-001',
            cssContent: 'body { color: red; }',
            tokenOverrides: ['--primary' => '#ff0000'],
            reason: 'Initial customization',
            createdBy: 'actor-001',
        );

        self::assertSame(1, $v1->version);
        self::assertTrue($v1->isActive);

        $v2 = $service->saveOverrides(
            themeId: 'theme-001',
            cssContent: 'body { color: blue; }',
            tokenOverrides: ['--primary' => '#0000ff'],
            reason: 'Changed to blue',
            createdBy: 'actor-001',
        );

        self::assertSame(2, $v2->version);
        self::assertTrue($v2->isActive);
    }

    // ── rollback creates NEW version with old content ───────────────

    #[Test]
    public function rollbackCreatesNewVersionWithOldContent(): void
    {
        $service = $this->createService();

        $v1 = $service->saveOverrides(
            themeId: 'theme-001',
            cssContent: 'body { color: red; }',
            tokenOverrides: [],
            reason: 'v1',
            createdBy: 'actor-001',
        );

        $service->saveOverrides(
            themeId: 'theme-001',
            cssContent: 'body { color: blue; }',
            tokenOverrides: [],
            reason: 'v2',
            createdBy: 'actor-001',
        );

        $v3 = $service->rollback($v1->id, 'Reverting to v1', 'actor-001');

        self::assertSame(3, $v3->version);
        self::assertSame('body { color: red; }', $v3->cssContent);
        self::assertTrue($v3->isActive);
    }

    // ── getVersionHistory returns ordered list ───────────────────────

    #[Test]
    public function getVersionHistoryReturnsOrderedList(): void
    {
        $service = $this->createService();

        $service->saveOverrides('theme-001', 'css-v1', [], 'v1', 'actor-001');
        $service->saveOverrides('theme-001', 'css-v2', [], 'v2', 'actor-001');
        $service->saveOverrides('theme-001', 'css-v3', [], 'v3', 'actor-001');

        $history = $service->getVersionHistory('theme-001', null);

        self::assertCount(3, $history);
        self::assertSame(1, $history[0]->version);
        self::assertSame(2, $history[1]->version);
        self::assertSame(3, $history[2]->version);
    }

    // ── Rollback of non-existent override throws ────────────────────

    #[Test]
    public function rollbackNonexistentThrows(): void
    {
        $service = $this->createService();

        $this->expectException(CmsException::class);

        $service->rollback('nonexistent-id', 'reason', 'actor-001');
    }

    private function createService(): LiveCssServiceInterface
    {
        $repo = $this->createRepo();
        $validator = $this->createCssValidator();
        $hashComputer = $this->createHashComputer();

        return new class ($repo, $validator, $hashComputer) implements LiveCssServiceInterface {
            public function __construct(
                private readonly CssOverrideRepositoryInterface $repo,
                private readonly CssValidatorInterface $validator,
                private readonly CspHashComputerInterface $hashComputer,
            ) {}

            public function getCurrentOverrides(string $themeId, ?string $tenantId = null): ?CssOverride
            {
                return $this->repo->findActive($themeId, $tenantId);
            }

            public function saveOverrides(
                string $themeId,
                string $cssContent,
                array $tokenOverrides,
                string $reason,
                string $createdBy,
                ?string $tenantId = null,
            ): CssOverride {
                $validation = $this->validator->validate($cssContent);

                if (!$validation->isValid) {
                    throw new CmsException('CSS validation failed: ' . implode(', ', $validation->errors));
                }

                $this->repo->deactivateAll($themeId, $tenantId);

                $nextVersion = $this->repo->getNextVersion($themeId, $tenantId);
                $cssHash = $this->hashComputer->computeHash($cssContent);

                $override = CssOverride::create(
                    id: 'override-' . $nextVersion,
                    themeId: $themeId,
                    version: $nextVersion,
                    cssContent: $cssContent,
                    cssHash: $cssHash,
                    tokenOverrides: $tokenOverrides,
                    createdBy: $createdBy,
                    reason: $reason,
                    tenantId: $tenantId,
                );

                $this->repo->save($override);

                return $override;
            }

            public function rollback(string $overrideId, string $reason, string $actorId): CssOverride
            {
                $old = $this->repo->findById($overrideId);

                if ($old === null) {
                    throw CmsException::contentNotFound($overrideId);
                }

                return $this->saveOverrides(
                    themeId: $old->themeId,
                    cssContent: $old->cssContent,
                    tokenOverrides: $old->tokenOverrides,
                    reason: $reason,
                    createdBy: $actorId,
                    tenantId: $old->tenantId,
                );
            }

            public function getVersionHistory(string $themeId, ?string $tenantId, int $page = 1, int $perPage = 20): array
            {
                return $this->repo->getHistory($themeId, $tenantId, $page, $perPage);
            }
        };
    }

    private function createRepo(): CssOverrideRepositoryInterface
    {
        return new class implements CssOverrideRepositoryInterface {
            /** @var array<string, CssOverride> */
            private array $overrides = [];

            public function findActive(string $themeId, ?string $tenantId = null): ?CssOverride
            {
                foreach ($this->overrides as $o) {
                    if ($o->themeId === $themeId && $o->tenantId === $tenantId && $o->isActive) {
                        return $o;
                    }
                }

                return null;
            }

            public function findByVersion(string $themeId, ?string $tenantId, int $version): ?CssOverride
            {
                foreach ($this->overrides as $o) {
                    if ($o->themeId === $themeId && $o->tenantId === $tenantId && $o->version === $version) {
                        return $o;
                    }
                }

                return null;
            }

            public function findById(string $id): ?CssOverride
            {
                return $this->overrides[$id] ?? null;
            }

            public function getHistory(string $themeId, ?string $tenantId, int $page, int $perPage): array
            {
                $matching = array_values(array_filter(
                    $this->overrides,
                    static fn(CssOverride $o) => $o->themeId === $themeId && $o->tenantId === $tenantId,
                ));

                usort($matching, static fn(CssOverride $a, CssOverride $b) => $a->version <=> $b->version);

                $offset = ($page - 1) * $perPage;

                return array_slice($matching, $offset, $perPage);
            }

            public function getNextVersion(string $themeId, ?string $tenantId): int
            {
                $maxVersion = 0;

                foreach ($this->overrides as $o) {
                    if ($o->themeId === $themeId && $o->tenantId === $tenantId && $o->version > $maxVersion) {
                        $maxVersion = $o->version;
                    }
                }

                return $maxVersion + 1;
            }

            public function save(CssOverride $override): void
            {
                $this->overrides[$override->id] = $override;
            }

            public function deactivateAll(string $themeId, ?string $tenantId): void
            {
                foreach ($this->overrides as $id => $o) {
                    if ($o->themeId === $themeId && $o->tenantId === $tenantId && $o->isActive) {
                        $this->overrides[$id] = new CssOverride(
                            id: $o->id,
                            tenantId: $o->tenantId,
                            themeId: $o->themeId,
                            version: $o->version,
                            cssContent: $o->cssContent,
                            cssHash: $o->cssHash,
                            tokenOverrides: $o->tokenOverrides,
                            isActive: false,
                            createdAt: $o->createdAt,
                            createdBy: $o->createdBy,
                            reason: $o->reason,
                        );
                    }
                }
            }
        };
    }

    private function createCssValidator(): CssValidatorInterface
    {
        return new class implements CssValidatorInterface {
            public function validate(string $cssContent): CssValidationResult
            {
                return new CssValidationResult(true, [], $cssContent);
            }
        };
    }

    private function createHashComputer(): CspHashComputerInterface
    {
        return new class implements CspHashComputerInterface {
            public function computeHash(string $styleContent): string
            {
                return 'sha256-' . base64_encode(hash('sha256', $styleContent, true));
            }
        };
    }
}
