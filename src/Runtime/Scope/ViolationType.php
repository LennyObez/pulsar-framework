<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Scope;

use Pulsar\Api\Internal;

#[Internal]
enum ViolationType: string
{
    case WritableProperty = 'writable_property';
    case MutableStatic = 'mutable_static';
    case ResetMethod = 'reset_method';
}
