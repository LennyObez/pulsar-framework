<?php

declare(strict_types=1);

namespace Pulsar\I18n\Linter;

use Pulsar\Api\Internal;

/**
 * Severity level for translation lint issues.
 */
#[Internal]
enum LintSeverity: string
{
    case Warning = 'warning';
    case Error = 'error';
}
