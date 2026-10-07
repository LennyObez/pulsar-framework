<?php

declare(strict_types=1);

namespace Pulsar\Security\Dlp;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Result of a DLP scan: what was found, where, what action was taken, and
 * whether the scanner actually looked.
 *
 * The last of those is {@see $status}, and it is the difference between a
 * scanner that read the content and found nothing and one that never read it at
 * all. See {@see DlpScanStatus} for why both are reachable and why the
 * distinction is load-bearing for anything that gates egress on the answer.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DlpScanResult
{
    /**
     * @param list<DlpMatch>  $matches
     * @param DlpScanStatus   $status  Whether the scan examined the content;
     *                                 defaults to {@see DlpScanStatus::Completed}
     *                                 so that existing callers constructing a
     *                                 result from a scan they performed keep
     *                                 their meaning
     */
    public function __construct(
        public bool $detected,
        public DlpAction $actionTaken,
        public array $matches,
        public string $redactedContent,
        public DlpScanStatus $status = DlpScanStatus::Completed,
    ) {}

    /**
     * A scan that ran and found nothing.
     *
     * Reserved for exactly that. A scanner that could not run says so with
     * {@see disabled()} or {@see failed()} rather than borrowing this one — the
     * borrowing is the defect {@see DlpScanStatus} was added to end.
     */
    #[NoDiscard]
    public static function clean(string $content): self
    {
        return new self(
            detected: false,
            actionTaken: DlpAction::Alert,
            matches: [],
            redactedContent: $content,
            status: DlpScanStatus::Completed,
        );
    }

    /**
     * DLP is switched off; the content was not examined.
     *
     * The content is returned unchanged because there is nothing to redact
     * against — no pattern was consulted — not because it was found to be clean.
     */
    #[NoDiscard]
    public static function disabled(string $content): self
    {
        return new self(
            detected: false,
            actionTaken: DlpAction::Alert,
            matches: [],
            redactedContent: $content,
            status: DlpScanStatus::Disabled,
        );
    }

    /**
     * At least one pattern's match attempt failed inside the PCRE engine.
     *
     * Carries whatever the patterns that did complete found, so a caller that
     * decides to act on partial information can, while {@see $status} keeps it
     * from being read as the whole answer.
     *
     * @param list<DlpMatch> $matches Matches from the patterns that did complete
     */
    #[NoDiscard]
    public static function failed(string $content, array $matches, DlpAction $actionTaken): self
    {
        return new self(
            detected: $matches !== [],
            actionTaken: $actionTaken,
            matches: $matches,
            redactedContent: $content,
            status: DlpScanStatus::Failed,
        );
    }
}
