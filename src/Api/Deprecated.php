<?php

declare(strict_types=1);

namespace Pulsar\Api;

use Attribute;

/**
 * Marks a class, method, or constant as deprecated.
 *
 * When applied, the first runtime call triggers an E_USER_DEPRECATED notice.
 * Static analysis tools can detect usage and recommend migrations.
 *
 * @example #[Deprecated(since: '1.1', removeIn: '2.0', replacement: 'newMethod()')]
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_CLASS_CONSTANT | Attribute::TARGET_FUNCTION)]
#[Api(since: '1.0.0')]
final readonly class Deprecated
{
    public function __construct(
        public string $since = '',
        public string $removeIn = '',
        public string $replacement = '',
        public string $reason = '',
    ) {}

    /**
     * Build the deprecation message for E_USER_DEPRECATED.
     */
    public function message(string $symbol): string
    {
        $msg = "$symbol is deprecated";

        if ($this->since !== '') {
            $msg .= " since $this->since";
        }

        if ($this->removeIn !== '') {
            $msg .= ", will be removed in $this->removeIn";
        }

        if ($this->replacement !== '') {
            $msg .= ". Use $this->replacement instead";
        }

        if ($this->reason !== '') {
            $msg .= ". Reason: $this->reason";
        }

        return $msg . '.';
    }
}
