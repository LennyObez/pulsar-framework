<?php

declare(strict_types=1);

namespace Pulsar\Security\JustifiedAccess;

use Attribute;
use Pulsar\Api\Api;
use Pulsar\Security\Compliance\DataClassification;

/**
 * Marks a route handler as requiring access justification.
 *
 * When applied, the JustifiedAccessMiddleware intercepts the request and
 * requires the caller to provide a justification before accessing the
 * protected resource.
 *
 * Usage:
 *   #[RequiresJustification(dataClassification: DataClassification::Restricted)]
 *   public function viewPatientRecord(string $id): Response { ... }
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
#[Api(since: '1.0.0')]
final readonly class RequiresJustification
{
    /**
     * @param list<JustificationCategory>|null $allowedCategories Restrict to specific categories (null = all)
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public DataClassification $dataClassification = DataClassification::Confidential,
        public bool $requireSupervisorApproval = false,
        public ?array $allowedCategories = null,
    ) {}
}
