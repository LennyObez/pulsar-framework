<?php

declare(strict_types=1);

namespace Pulsar\Database;

use Pulsar\Api\Api;

use function str_contains;
use function strtolower;

/**
 * Database driver variant detection.
 *
 * Distinguishes between standard MySQL, MariaDB, and Percona Server
 * based on the VERSION() output string.
 * @api
 */
#[Api(since: '1.0.0')]
enum DriverVariant: string
{
    case Standard = 'standard';
    case MariaDb = 'mariadb';
    case PerconaServer = 'percona';

    /**
     * Detect the driver variant from a VERSION() string.
     *
     * Examples:
     *   "10.5.18-MariaDB" → MariaDb
     *   "8.0.35-26-Percona Server" → PerconaServer
     *   "8.0.35" → Standard
     */
    public static function detect(string $versionString): self
    {
        $lower = strtolower($versionString);

        if (str_contains($lower, 'mariadb')) {
            return self::MariaDb;
        }

        if (str_contains($lower, 'percona')) {
            return self::PerconaServer;
        }

        return self::Standard;
    }
}
