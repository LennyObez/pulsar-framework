<?php

declare(strict_types=1);

namespace Pulsar\Database;

use Pulsar\Database\Migration\MigrationInterface as BaseMigrationInterface;

/**
 * Convenience alias for {@see \Pulsar\Database\Migration\MigrationInterface}.
 *
 * The canonical location is `Pulsar\Database\Migration\MigrationInterface`.
 * This alias exists so that `use Pulsar\Database\MigrationInterface` works
 * as developers commonly expect.
 */
interface MigrationInterface extends BaseMigrationInterface {}
