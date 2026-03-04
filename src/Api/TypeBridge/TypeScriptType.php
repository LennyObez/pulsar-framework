<?php

declare(strict_types=1);

namespace Pulsar\Api\TypeBridge;

use Pulsar\Api\Api;

/**
 * TypeScript type representation for code generation.
 */
#[Api(since: '1.0.0')]
enum TypeScriptType: string
{
    case String = 'string';
    case Number = 'number';
    case Boolean = 'boolean';
    case Null = 'null';
    case Undefined = 'undefined';
    case Any = 'any';
    case Unknown = 'unknown';
    case Void = 'void';
    case Never = 'never';
    case Object = 'Record<string, unknown>';
    case StringArray = 'string[]';
    case NumberArray = 'number[]';
}
