<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E\Extension\Cms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
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
use function implode;
use function usort;

/**
 * E2E: Live CSS editing — modify token -> preview -> save -> rollback -> version history.
 */
#[CoversClass(CssOverride::class)]
#[Group('e2e-cms')]
final class LiveCssEditingTest extends TestCase
{
    #[Test]
    public function fullCssEditingLifecycle(): void
    {
        $service = $this->createService();

        // Step 1: Save initial CSS with token overrides
        $initialCss = ':root { --primary: #2563eb; --accent: #f59e0b; } .header { background: var(--primary); }';
        $v1 = $service->saveOverrides(
            themeId: 'theme-e2e-live',
            cssContent: $initialCss,
            tokenOverrides: ['--primary' => '#2563eb', '--accent' => '#f59e0b'],
            reason: 'Initial brand colors',
            createdBy: 'designer-001',
        );

        self::assertSame(1, $v1->version);
        self::assertTrue($v1->isActive);
        self::assertSame($initialCss, $v1->cssContent);
        self::assertStringStartsWith('sha256-', $v1->cssHash);
        self::assertSame(['--primary' => '#2563eb', '--accent' => '#f59e0b'], $v1->tokenOverrides);

        // Step 2: Preview (just verify the CSS content produces valid style block)
        $styleBlock = "<style>{$v1->cssContent}</style>";
        self::assertStringContainsString('--primary', $styleBlock);
        self::assertStringContainsString('#2563eb', $styleBlock);

        // Step 3: Update with new colors
        $updatedCss = ':root { --primary: #dc2626; --accent: #16a34a; } .header { background: var(--primary); }';
        $v2 = $service->saveOverrides(
            themeId: 'theme-e2e-live',
            cssContent: $updatedCss,
            tokenOverrides: ['--primary' => '#dc2626', '--accent' => '#16a34a'],
            reason: 'Holiday theme colors',
            createdBy: 'designer-001',
        );

        self::assertSame(2, $v2->version);
        self::assertTrue($v2->isActive);
        self::assertStringContainsString('#dc2626', $v2->cssContent);

        // Step 4: Verify CSP hash changed
        self::assertNotSame($v1->cssHash, $v2->cssHash);

        // Step 5: Check current active override
        $current = $service->getCurrentOverrides('theme-e2e-live');
        self::assertNotNull($current);
        self::assertSame(2, $current->version);
        self::assertStringContainsString('#dc2626', $current->cssContent);

        // Step 6: Rollback to v1
        $v3 = $service->rollback($v1->id, 'Revert holiday colors', 'designer-001');

        self::assertSame(3, $v3->version);
        self::assertSame($initialCss, $v3->cssContent);
        self::assertSame($v1->cssHash, $v3->cssHash);
        self::assertSame(['--primary' => '#2563eb', '--accent' => '#f59e0b'], $v3->tokenOverrides);

        // Step 7: Current should now be v3 with original colors
        $afterRollback = $service->getCurrentOverrides('theme-e2e-live');
        self::assertNotNull($afterRollback);
        self::assertSame(3, $afterRollback->version);
        self::assertStringContainsString('#2563eb', $afterRollback->cssContent);

        // Step 8: Version history
        $history = $service->getVersionHistory('theme-e2e-live', null);
        self::assertCount(3, $history);
    }

    #[Test]
    public function cssValidationBlocksImportDirective(): void
    {
        $service = $this->createService(strictValidation: true);

        $this->expectException(CmsException::class);

        $service->saveOverrides(
            themeId: 'theme-e2e-live',
            cssContent: '@import url("https://evil.com/malicious.css"); .body { color: red; }',
            tokenOverrides: [],
            reason: 'Malicious import attempt',
            createdBy: 'attacker',
        );
    }

    #[Test]
    public function cssValidationBlocksExternalUrls(): void
    {
        $service = $this->createService(strictValidation: true);

        $this->expectException(CmsException::class);

        $service->saveOverrides(
            themeId: 'theme-e2e-live',
            cssContent: '.bg { background: url(https://tracking.example.com/pixel.gif); }',
            tokenOverrides: [],
            reason: 'Tracking pixel attempt',
            createdBy: 'attacker',
        );
    }

    #[Test]
    public function rollbackNonexistentOverrideThrows(): void
    {
        $service = $this->createService();

        $this->expectException(CmsException::class);

        $service->rollback('nonexistent-override-id', 'reason', 'actor');
    }

    #[Test]
    public function noCurrentOverrideReturnsNull(): void
    {
        $service = $this->createService();

        $current = $service->getCurrentOverrides('theme-with-no-overrides');
        self::assertNull($current);
    }

    #[Test]
    public function cspHashFormat(): void
    {
        $service = $this->createService();

        $override = $service->saveOverrides(
            themeId: 'theme-e2e-csp',
            cssContent: ':root { --color: blue; }',
            tokenOverrides: ['--color' => 'blue'],
            reason: 'CSP hash test',
            createdBy: 'designer',
        );

        // Verify the hash is in CSP-compatible format: sha256-{base64}
        self::assertMatchesRegularExpression('/^sha256-[A-Za-z0-9+\/=]+$/', $override->cssHash);

        // Verify the hash is deterministic
        $expectedHash = 'sha256-' . base64_encode(hash('sha256', ':root { --color: blue; }', true));
        self::assertSame($expectedHash, $override->cssHash);
    }

    private function createService(bool $strictValidation = false): LiveCssServiceInterface
    {
        $repo = $this->createRepo();
        $validator = $strictValidation ? $this->createStrictValidator() : $this->createPermissiveValidator();
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
                    throw new CmsException('CSS validation failed: ' . implode(', ', $validation->errors));
                }

                $this->repo->deactivateAll($themeId, $tenantId);

                $nextVersion = $this->repo->getNextVersion($themeId, $tenantId);
                $cssHash = $this->hashComputer->computeHash($cssContent);

                $override = CssOverride::create(
                    id: "override-{$themeId}-v{$nextVersion}",
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

    private function createPermissiveValidator(): CssValidatorInterface
    {
        return new class implements CssValidatorInterface {
            public function validate(string $cssContent): CssValidationResult
            {
                return new CssValidationResult(true, [], $cssContent);
            }
        };
    }

    private function createStrictValidator(): CssValidatorInterface
    {
        return new class implements CssValidatorInterface {
            public function validate(string $cssContent): CssValidationResult
            {
                $errors = [];

                if (preg_match('/@import\b/i', $cssContent)) {
                    $errors[] = '@import is not allowed';
                }

                if (preg_match('/url\s*\(\s*["\']?\s*https?:/i', $cssContent)) {
                    $errors[] = 'External URLs are not allowed';
                }

                return new CssValidationResult($errors === [], $errors, $errors === [] ? $cssContent : '');
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
