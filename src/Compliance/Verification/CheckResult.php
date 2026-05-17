<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use Pulsar\Api\Api;

/**
 * Result of a single compliance check execution.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CheckResult
{
    /**
     * @param list<string> $evidence      Supporting evidence for the result
     * @param list<string> $remediations  Suggested fixes when the check fails
     */
    public function __construct(
        public string $checkId,
        public CheckStatus $status,
        public string $message,
        public ComplianceCheckDomain $domain = ComplianceCheckDomain::Encryption,
        public array $evidence = [],
        public array $remediations = [],
        public ?int $verifiedAt = null,
    ) {}

    /**
     * @param list<string> $evidence
     */
    public static function pass(
        string $checkId,
        string $message,
        ComplianceCheckDomain $domain = ComplianceCheckDomain::Encryption,
        array $evidence = [],
    ): self {
        return new self(
            checkId: $checkId,
            status: CheckStatus::Pass,
            message: $message,
            domain: $domain,
            evidence: $evidence,
            verifiedAt: time(),
        );
    }

    /**
     * @param list<string> $remediations
     */
    public static function fail(
        string $checkId,
        string $message,
        ComplianceCheckDomain $domain = ComplianceCheckDomain::Encryption,
        array $remediations = [],
    ): self {
        return new self(
            checkId: $checkId,
            status: CheckStatus::Fail,
            message: $message,
            domain: $domain,
            remediations: $remediations,
            verifiedAt: time(),
        );
    }

    public static function skip(
        string $checkId,
        string $message,
        ComplianceCheckDomain $domain = ComplianceCheckDomain::Encryption,
    ): self {
        return new self(
            checkId: $checkId,
            status: CheckStatus::Skip,
            message: $message,
            domain: $domain,
            verifiedAt: time(),
        );
    }
}
