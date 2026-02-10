<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms;

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

use function array_filter;
use function array_slice;
use function array_values;
use function base64_encode;
use function hash;
use function usort;

#[CoversClass(CssOverride::class)]
final class LiveCssIntegrationTest extends TestCase
{
    #[Test]
    public function saveRollbackVersionHistoryFlow(): void
    {
        $service = $this->createLiveCssService();

        // Save CSS overrides -> verify version = 1
        $v1 = $service->saveOverrides(
            themeId: 'theme-001',
            cssContent: ':root { --primary: #ff0000; } body { color: var(--primary); }',
            tokenOverrides: ['--primary' => '#ff0000'],
            reason: 'Initial branding',
            createdBy: 'admin-001',
        );

        self::assertSame(1, $v1->version);
        self::assertTrue($v1->isActive);
        self::assertNotEmpty($v1->cssHash);
        self::assertStringStartsWith('sha256-', $v1->cssHash);

        // Save again -> verify version = 2
        $v2 = $service->saveOverrides(
            themeId: 'theme-001',
            cssContent: ':root { --primary: #0000ff; } body { color: var(--primary); }',
            tokenOverrides: ['--primary' => '#0000ff'],
            reason: 'Changed to blue',
            createdBy: 'admin-001',
        );

        self::assertSame(2, $v2->version);
        self::assertTrue($v2->isActive);

        // Verify v1 is now inactive
        $current = $service->getCurrentOverrides('theme-001');
        self::assertNotNull($current);
        self::assertSame(2, $current->version);

        // Rollback to v1 -> verify version = 3 with v1 content
        $v3 = $service->rollback($v1->id, 'Reverting to red branding', 'admin-001');

        self::assertSame(3, $v3->version);
        self::assertSame($v1->cssContent, $v3->cssContent);
        self::assertSame($v1->tokenOverrides, $v3->tokenOverrides);
        self::assertTrue($v3->isActive);

        // Verify CSP hash matches content
        $expectedHash = 'sha256-' . base64_encode(hash('sha256', $v3->cssContent, true));
        self::assertSame($expectedHash, $v3->cssHash);

        // Verify version history
        $history = $service->getVersionHistory('theme-001', null);
        self::assertCount(3, $history);
        self::assertSame(1, $history[0]->version);
        self::assertSame(2, $history[1]->version);
        self::assertSame(3, $history[2]->version);
    }

    #[Test]
    public function multiTenantIsolation(): void
    {
        $service = $this->createLiveCssService();

        $tenantA = $service->saveOverrides(
            themeId: 'theme-001',
            cssContent: 'body { color: red; }',
            tokenOverrides: [],
            reason: 'Tenant A',
            createdBy: 'admin-a',
            tenantId: 'tenant-a',
        );

        $tenantB = $service->saveOverrides(
            themeId: 'theme-001',
            cssContent: 'body { color: blue; }',
            tokenOverrides: [],
            reason: 'Tenant B',
            createdBy: 'admin-b',
            tenantId: 'tenant-b',
        );

        self::assertSame(1, $tenantA->version);
        self::assertSame(1, $tenantB->version);

        $currentA = $service->getCurrentOverrides('theme-001', 'tenant-a');
        $currentB = $service->getCurrentOverrides('theme-001', 'tenant-b');

        self::assertNotNull($currentA);
        self::assertNotNull($currentB);
        self::assertSame('body { color: red; }', $currentA->cssContent);
        self::assertSame('body { color: blue; }', $currentB->cssContent);
    }

    private function createLiveCssService(): LiveCssServiceInterface
    {
        $repo = $this->createRepo();
        $validator = new class implements CssValidatorInterface {
            public function validate(string $cssContent): CssValidationResult
            {
                return new CssValidationResult(true, [], $cssContent);
            }
        };
        $hashComputer = new class implements CspHashComputerInterface {
            public function computeHash(string $styleContent): string
            {
                return 'sha256-' . base64_encode(hash('sha256', $styleContent, true));
            }
        };

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
                    throw new CmsException('CSS validation failed');
                }

                $this->repo->deactivateAll($themeId, $tenantId);

                $nextVersion = $this->repo->getNextVersion($themeId, $tenantId);
                $cssHash = $this->hashComputer->computeHash($cssContent);

                $override = CssOverride::create(
                    id: "override-{$themeId}-{$tenantId}-{$nextVersion}",
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
                    $old->themeId,
                    $old->cssContent,
                    $old->tokenOverrides,
                    $reason,
                    $actorId,
                    $old->tenantId,
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

                return array_slice($matching, ($page - 1) * $perPage, $perPage);
            }

            public function getNextVersion(string $themeId, ?string $tenantId): int
            {
                $max = 0;

                foreach ($this->overrides as $o) {
                    if ($o->themeId === $themeId && $o->tenantId === $tenantId && $o->version > $max) {
                        $max = $o->version;
                    }
                }

                return $max + 1;
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
                            $o->id,
                            $o->tenantId,
                            $o->themeId,
                            $o->version,
                            $o->cssContent,
                            $o->cssHash,
                            $o->tokenOverrides,
                            false,
                            $o->createdAt,
                            $o->createdBy,
                            $o->reason,
                        );
                    }
                }
            }
        };
    }
}
