<?php

declare(strict_types=1);

namespace Pulsar\Codegen\Schema;

use Pulsar\Api\Api;

/**
 * Types of schema change operations detected by the diff engine.
 */
#[Api(since: '1.0.0')]
enum SchemaOperationType: string
{
    case CreateTable = 'create_table';
    case DropTable = 'drop_table';
    case AddColumn = 'add_column';
    case DropColumn = 'drop_column';
    case ModifyColumn = 'modify_column';
    case AddIndex = 'add_index';
    case DropIndex = 'drop_index';
    case AddForeignKey = 'add_foreign_key';
    case DropForeignKey = 'drop_foreign_key';
}
