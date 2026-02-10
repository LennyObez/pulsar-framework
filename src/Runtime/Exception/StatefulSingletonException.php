<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Exception;

use Pulsar\Api\Api;
use Pulsar\Runtime\Scope\StatefulSingletonViolation;
use RuntimeException;

use function array_map;
use function count;
use function implode;
use function sprintf;

#[Api(since: '1.0.0')]
final class StatefulSingletonException extends RuntimeException
{
    /** @param list<StatefulSingletonViolation> $violations */
    public static function detected(array $violations): self
    {
        $count = count($violations);
        $details = implode("\n  - ", array_map(
            static fn(StatefulSingletonViolation $v): string => $v->message,
            $violations,
        ));

        return new self(sprintf(
            "Detected %d stateful singleton violation(s) in core namespaces (strict mode):\n  - %s",
            $count,
            $details,
        ));
    }
}
