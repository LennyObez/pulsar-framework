<?php

declare(strict_types=1);

namespace Pulsar\Audit\Exception;

use Pulsar\Api\Api;
use RuntimeException;

/**
 * Raised when `AuditLogger::log()` is invoked without a resolvable actor.
 *
 * Audit records must always identify a responsible party. Falling back to a
 * generic `'system'` placeholder hides unauthenticated activity, blinds
 * compliance reviewers, and is a known historical defect (F25.10). When the
 * actor parameter is null and the active `RequestContext` does not carry an
 * actor either, this exception is thrown to force callers to pass an explicit
 * `AuditActor` (e.g. `AuditActor::system('mail.webhook')`).
 * @api
 */
#[Api(since: '1.0.0')]
final class AuditActorMissingException extends RuntimeException
{
    public static function notProvidedAndNoContext(string $action): self
    {
        return new self(
            'Audit log requires an explicit actor. Action "' . $action
            . '" was logged with a null actor and no RequestContext provided one. '
            . 'Pass an AuditActor (e.g. AuditActor::system("<component>")) or '
            . 'a string identifier instead of relying on the previous "system" fallback.',
        );
    }
}
