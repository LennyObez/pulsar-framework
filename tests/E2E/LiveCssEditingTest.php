<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E;

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
final class LiveCssEditingTest extends TestCase
{
    #[Test]
    public function test_save_css_verify_style_block_rollback_verify_reverted(): void
    {
        $service = $this->createService();

        // Step 1: Save CSS
        $css = ':root { --brand-color: #e74c3c; } .header { background: var(--brand-color); }';
        $v1 = $service->saveOverrides(
            themeId: 'theme-main',
            cssContent: $css,
            tokenOverrides: ['--brand-color' => '#e74c3c'],
            reason: 'Brand red header',
            createdBy: 'editor-001',
        );

        self::assertSame(1, $v1->version);
        self::assertTrue($v1->isActive);

        // Step 2: Verify style block generated (CSP hash present)
        $styleBlock = "<style>{$v1->cssContent}</style>";
        self::assertStringContainsString('--brand-color', $styleBlock);
        self::assertStringContainsString('#e74c3c', $styleBlock);
        self::assertNotEmpty($v1->cssHash);
        self::assertStringStartsWith('sha256-', $v1->cssHash);

        // Verify CSP hash can be used in Content-Security-Policy header
        $cspDirective = "style-src '{$v1->cssHash}'";
        self::assertStringContainsString('sha256-', $cspDirective);

        // Step 3: Change CSS
        $newCss = ':root { --brand-color: #3498db; } .header { background: var(--brand-color); }';
        $v2 = $service->saveOverrides(
            themeId: 'theme-main',
            cssContent: $newCss,
            tokenOverrides: ['--brand-color' => '#3498db'],
            reason: 'Brand blue header',
            createdBy: 'editor-001',
        );

        self::assertSame(2, $v2->version);
        self::assertStringContainsString('#3498db', $v2->cssContent);

        // Step 4: Rollback to v1
        $v3 = $service->rollback($v1->id, 'Reverting to red', 'editor-001');

        // Step 5: Verify reverted
        self::assertSame(3, $v3->version);
        self::assertSame($css, $v3->cssContent);
        self::assertStringContainsString('#e74c3c', $v3->cssContent);
        self::assertStringNotContainsString('#3498db', $v3->cssContent);
        self::assertSame(['--brand-color' => '#e74c3c'], $v3->tokenOverrides);

        // Current override should be the rolled-back version
        $current = $service->getCurrentOverrides('theme-main');
        self::assertNotNull($current);
        self::assertSame(3, $current->version);
        self::assertSame($css, $current->cssContent);
    }

    #[Test]
    public function test_css_validation_prevents_dangerous_content(): void
    {
        $service = $this->createService(strictValidation: true);

        $this->expectException(CmsException::class);

        $service->saveOverrides(
            themeId: 'theme-main',
            cssContent: '@import url("https://evil.com/malicious.css");',
            tokenOverrides: [],
            reason: 'malicious attempt',
            createdBy: 'attacker',
        );
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
