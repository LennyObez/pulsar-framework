<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Pulsar\Api\Api;

/**
 * A typed config DTO that reports the keys it did not recognize.
 *
 * Every `fromArray()` that reads an operator-editable config file implements
 * this so a misspelled key (`handler` written as `driver`, `path` as `pathh`)
 * surfaces at boot instead of being silently dropped to a default. The
 * {@see UnknownKeys} helper computes the list; {@see ConfigManager} collects it
 * across every loaded section and either warns or, in strict mode, fails closed.
 * @api
 */
#[Api(since: '1.0.0')]
interface ReportsUnknownKeys
{
    /**
     * Keys present in the raw config array that this DTO's fromArray() does not
     * read — i.e. typos or stale keys the operator likely meant to be effective.
     *
     * @return list<string>
     */
    public function unknownConfigKeys(): array;
}
