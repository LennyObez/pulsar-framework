<?php

declare(strict_types=1);

namespace Pulsar\Api;

/**
 * Severity of a backward-compatibility break.
 *
 * Error = BC break on stable API (blocks CI).
 * Warning = BC break on experimental API (logged but not blocking).
 */
#[Api(since: '1.0.0')]
enum BcBreakSeverity: string
{
    case Error = 'error';
    case Warning = 'warning';
}
