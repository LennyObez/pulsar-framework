<?php

declare(strict_types=1);

namespace Pulsar\Api;

/**
 * Types of backward-compatibility breaks.
 */
#[Api(since: '1.0.0')]
enum BcBreakType: string
{
    case ClassRemoved = 'class_removed';
    case MethodRemoved = 'method_removed';
    case SignatureChanged = 'signature_changed';
    case ReturnTypeNarrowed = 'return_type_narrowed';
    case ConstantRemoved = 'constant_removed';
}
