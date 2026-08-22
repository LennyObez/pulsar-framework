<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Geo;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Security\ZeroTrust\Signal\GeoLocation;
use Pulsar\Security\ZeroTrust\Signal\GeoLocationResolverInterface;

use function count;
use function fclose;
use function fgets;
use function fopen;
use function fseek;
use function fstat;
use function ftell;
use function intdiv;
use function ip2long;
use function is_file;
use function realpath;
use function str_getcsv;
use function strtoupper;
use function trim;

/**
 * Country-level geo resolution using DB-IP Lite database (CC-BY-4.0).
 *
 * Uses a file-based binary search to avoid loading the entire CSV into memory.
 * The DB-IP Lite country CSV is ~8MB and contains ~500K rows. Binary search
 * on the file handle uses O(1) memory instead of O(n).
 *
 * The file handle is opened once and reused across all lookups within the
 * process lifetime, avoiding repeated open/close overhead.
 *
 * Falls back gracefully when the database file is not available.
 */
#[Internal(reason: 'Geo resolver; implements GeoLocationResolverInterface')]
final class DbIpLiteResolver implements GeoLocationResolverInterface
{
    private bool $resolved = false;

    /** @var resource|null */
    private mixed $handle = null;

    private int $fileSize = 0;

    public function __construct() {}

    public function __destruct()
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    #[Override]
    public function resolve(string $ip): ?GeoLocation
    {
        if (!$this->resolved) {
            $this->openDatabase();
            $this->resolved = true;
        }

        if ($this->handle === null) {
            return null;
        }

        $ipLong = ip2long($ip);

        if ($ipLong === false) {
            return null;
        }

        $country = $this->searchFile($ipLong);

        if ($country === null) {
            return null;
        }

        return new GeoLocation(
            latitude: 0.0,
            longitude: 0.0,
            country: $country,
        );
    }

    private function openDatabase(): void
    {
        $dbPath = $this->findDatabase();

        if ($dbPath === null) {
            return;
        }

        $handle = fopen($dbPath, 'r');

        if ($handle === false) {
            return;
        }

        $stat = fstat($handle);

        if ($stat === false) {
            fclose($handle);

            return;
        }

        $this->handle = $handle;
        $this->fileSize = $stat['size'];
    }

    private function findDatabase(): ?string
    {
        $paths = [
            __DIR__ . '/../../data/dbip-country-lite.csv',
            __DIR__ . '/../../../data/dbip-country-lite.csv',
        ];

        foreach ($paths as $path) {
            $real = realpath($path);

            if ($real !== false && is_file($real)) {
                return $real;
            }
        }

        return null;
    }

    /**
     * Binary search directly on the CSV file by byte offset.
     *
     * Each line is: start_ip,end_ip,country_code
     * Lines are sorted by start_ip, so we can binary search on byte offsets.
     */
    private function searchFile(int $ipLong): ?string
    {
        if ($this->handle === null) {
            return null;
        }

        $handle = $this->handle;
        $low = 0;
        $high = $this->fileSize - 1;
        $result = null;

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);

            // Seek to mid and align to next line start
            fseek($handle, $mid);

            if ($mid > 0) {
                // Skip partial line
                fgets($handle);
            }

            $line = fgets($handle);

            if ($line === false) {
                break;
            }

            $parts = str_getcsv(trim($line));

            if (count($parts) < 3) {
                // Corrupted line: move forward
                $low = $mid + 1;

                continue;
            }

            $start = ip2long((string) ($parts[0] ?? ''));
            $end = ip2long((string) ($parts[1] ?? ''));

            if ($start === false || $end === false) {
                $low = $mid + 1;

                continue;
            }

            if ($ipLong < $start) {
                $high = $mid - 1;
            } elseif ($ipLong > $end) {
                $low = ftell($this->handle) ?: $mid + 1;
            } else {
                $result = strtoupper(trim($parts[2] ?? ''));

                break;
            }
        }

        return $result;
    }
}
