<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\LiveCss;

use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\LiveCss\CspHashComputerInterface;
use Pulsar\Extension\Cms\LiveCss\CssOverride;
use Pulsar\Extension\Cms\LiveCss\CssOverrideRepositoryInterface;
use Pulsar\Extension\Cms\LiveCss\CssValidatorInterface;
use Pulsar\Extension\Cms\LiveCss\LiveCssServiceInterface;
use Pulsar\Extension\Cms\Support\UuidGenerator;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

/**
 * Live CSS override lifecycle: validate, version, persist, and audit.
 */
#[Internal(reason: 'Use LiveCssServiceInterface for public API')]
final readonly class LiveCssService implements LiveCssServiceInterface
{
    public function __construct(
        private CssOverrideRepositoryInterface $repository,
        private CssValidatorInterface $validator,
        private CspHashComputerInterface $hashComputer,
        private ?AuditLoggerInterface $auditLogger,
    ) {}

    public function getCurrentOverrides(string $themeId, ?string $tenantId = null): ?CssOverride
    {
        return $this->repository->findActive($themeId, $tenantId);
    }

    public function saveOverrides(
        string $themeId,
        string $cssContent,
        array $tokenOverrides,
        string $reason,
        string $createdBy,
        ?string $tenantId = null,
    ): CssOverride {
        $result = $this->validator->validate($cssContent);

        if (!$result->isValid) {
            throw new CmsException(
                'CSS validation failed: ' . implode('; ', $result->errors),
            );
        }

        $sanitizedCss = $result->sanitizedCss;
        $cssHash = $this->hashComputer->computeHash($sanitizedCss);
        $nextVersion = $this->repository->getNextVersion($themeId, $tenantId);

        $this->repository->deactivateAll($themeId, $tenantId);

        $override = CssOverride::create(
            id: UuidGenerator::v7(),
            themeId: $themeId,
            version: $nextVersion,
            cssContent: $sanitizedCss,
            cssHash: $cssHash,
            tokenOverrides: $tokenOverrides,
            createdBy: $createdBy,
            reason: $reason,
            tenantId: $tenantId,
        );

        $this->repository->save($override);

        $this->auditLogger?->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            $createdBy,
            'cms.livecss.saved',
            "theme:$themeId",
            ['version' => $nextVersion, 'reason' => $reason],
        );

        return $override;
    }

    public function rollback(string $overrideId, string $reason, string $actorId): CssOverride
    {
        $target = $this->repository->findById($overrideId);

        if ($target === null) {
            throw new CmsException("CSS override not found: $overrideId");
        }

        $nextVersion = $this->repository->getNextVersion($target->themeId, $target->tenantId);

        $this->repository->deactivateAll($target->themeId, $target->tenantId);

        $override = CssOverride::create(
            id: UuidGenerator::v7(),
            themeId: $target->themeId,
            version: $nextVersion,
            cssContent: $target->cssContent,
            cssHash: $target->cssHash,
            tokenOverrides: $target->tokenOverrides,
            createdBy: $actorId,
            reason: $reason,
            tenantId: $target->tenantId,
        );

        $this->repository->save($override);

        $this->auditLogger?->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            $actorId,
            'cms.livecss.rollback',
            "theme:$target->themeId",
            [
                'from_version' => $target->version,
                'to_version' => $nextVersion,
                'reason' => $reason,
            ],
        );

        return $override;
    }

    public function getVersionHistory(
        string $themeId,
        ?string $tenantId,
        int $page = 1,
        int $perPage = 20,
    ): array {
        return $this->repository->getHistory($themeId, $tenantId, $page, $perPage);
    }
}
