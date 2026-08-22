<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Themes;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Themes\ThemeRepositoryInterface;

/**
 * Safe mode fallback when the active theme fails integrity checks or is corrupted.
 *
 * Provides a minimal default template so the site remains functional while
 * the administrator resolves theme issues.
 *
 * @psalm-api Resolved from the DI container by the theme rendering pipeline;
 *            not instantiated by name.
 */
#[Internal(reason: 'CMS internal; theme safe mode handler')]
final readonly class ThemeSafeMode
{
    private const string SAFE_MODE_TEMPLATE_DIR = __DIR__ . '/../../resources/views/safe-mode';

    public function __construct(
        private ThemeRepositoryInterface $repository,
        private LoggerInterface $logger,
    ) {}

    /**
     * Whether the system should enter safe mode (active theme integrity compromised).
     */
    public function shouldActivate(?string $tenantId = null): bool
    {
        $active = $this->repository->findActive($tenantId);

        // No active theme: safe mode as fallback
        return $active === null;
    }

    /**
     * Get the safe mode default template path.
     */
    public function getDefaultTemplatePath(): string
    {
        return self::SAFE_MODE_TEMPLATE_DIR . '/default.pulse.php';
    }

    /**
     * Enter safe mode by deactivating the current theme and logging the reason.
     */
    public function enter(string $reason, ?string $tenantId = null): void
    {
        $active = $this->repository->findActive($tenantId);

        if ($active !== null) {
            $deactivated = $active->deactivate(new DateTimeImmutable());
            $this->repository->save($deactivated);

            $this->logger->critical('Entering theme safe mode: deactivating corrupt theme', [
                'theme_id' => $active->id,
                'slug' => $active->slug,
                'reason' => $reason,
            ]);
        } else {
            $this->logger->warning('Entering theme safe mode: no active theme', [
                'reason' => $reason,
            ]);
        }
    }
}
