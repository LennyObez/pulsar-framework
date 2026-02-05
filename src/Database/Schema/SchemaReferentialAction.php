<?php

declare(strict_types=1);

namespace Pulsar\Database\Schema;

use Pulsar\Api\Api;

/**
 * Referential actions for foreign key constraints.
 *
 * Typed enum prevents injection in ON DELETE/ON UPDATE clauses.
 */
#[Api(since: '1.0.0')]
enum SchemaReferentialAction: string
{
    case Restrict = 'RESTRICT';
    case Cascade = 'CASCADE';
    case SetNull = 'SET NULL';
    case NoAction = 'NO ACTION';
}
