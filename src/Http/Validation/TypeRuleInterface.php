<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation;

use Pulsar\Api\Api;

/**
 * Marker for rules that establish a value's basic type.
 *
 * F7.7: when a `TypeRuleInterface` rule fails (e.g. `IntegerType`,
 * `StringType`, `ArrayType`, `BooleanType`), the validator short-circuits
 * the remaining rules for that field. Otherwise downstream rules would
 * run against a value of the wrong type — for instance `Min` on a value
 * that is not even an integer — producing cascades of confusing
 * violation messages where one (the type mismatch) explains all of
 * them.
 * @api
 */
#[Api(since: '1.0.0')]
interface TypeRuleInterface extends RuleInterface {}
