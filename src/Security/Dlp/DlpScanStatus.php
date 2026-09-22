<?php

declare(strict_types=1);

namespace Pulsar\Security\Dlp;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Whether a DLP scan actually examined the content it was handed.
 *
 * {@see DlpScanResult::$detected} answers "did the scanner find anything", and
 * for two years that was the only question the result could answer. It is the
 * wrong question to build a boundary on, because a scanner that never ran and a
 * scanner that ran and found nothing both answer it with `false`.
 *
 * Both cases are reachable in shipped code:
 *
 *  - `DlpConfig::$enabled = false` makes {@see SensitivePatternRegistry::scan()}
 *    return {@see DlpScanResult::clean()} on its first line, without consulting a
 *    single pattern.
 *  - `preg_match_all()` returns `false` when the PCRE engine gives up — the
 *    backtrack and recursion limits — and the registry compared that return
 *    against `> 0`. `false > 0` is `false`, so an engine that exhausted its
 *    backtrack limit was indistinguishable from a pattern that matched nothing.
 *    Observed: `/^(?:[a-z]+)+$/` against forty `a`s and a `!` returns `false`
 *    with `preg_last_error() === PREG_BACKTRACK_LIMIT_ERROR`, and applications
 *    register their own patterns through the public
 *    {@see SensitivePatternRegistry::register()}.
 *
 * A consumer that only protects a log line can reasonably ignore the difference.
 * A consumer that decides whether personal data may leave the deployment cannot:
 * for it, "the scanner did not look" has to mean refusal, not consent. So the
 * fact is produced here, by the component that measures it, and each consumer
 * decides what it means — rather than being inferred by a second reading of the
 * same config somewhere else, where the two readings could disagree.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
enum DlpScanStatus: string
{
    /**
     * Every registered pattern ran to a verdict over the whole content.
     *
     * The only status under which `detected === false` means "there is nothing
     * of the kinds this registry knows about in these bytes".
     */
    case Completed = 'completed';

    /**
     * DLP is switched off, so nothing was examined.
     *
     * Not a clean bill of health. The content was never read.
     */
    case Disabled = 'disabled';

    /**
     * At least one pattern's match attempt failed inside the PCRE engine.
     *
     * Whatever that pattern would have caught is unknown, and the matches that
     * accompany this status are therefore a lower bound rather than the answer.
     */
    case Failed = 'failed';

    /**
     * Whether this status permits reading `detected === false` as "nothing is
     * there".
     *
     * The whole point of the enum in one predicate, so that a consumer states
     * the question rather than re-deriving it from the case list — and so that a
     * status added later has to be answered here rather than silently joining
     * whichever side of an inequality it happens to fall on.
     */
    #[NoDiscard]
    public function isConclusive(): bool
    {
        return match ($this) {
            self::Completed => true,
            self::Disabled, self::Failed => false,
        };
    }
}
