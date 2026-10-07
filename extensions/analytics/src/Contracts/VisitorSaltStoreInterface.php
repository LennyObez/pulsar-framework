<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Contracts;

use Pulsar\Api\Api;

/**
 * Store for the per-day random salts that key visitor hashing.
 *
 * Forward secrecy is the whole point: each UTC day gets a fresh random salt,
 * every visitor hash for that day is keyed by it, and once the salt is purged
 * (see {@see purgeOlderThan()}) the day's hashes can never be recomputed —
 * not even by an attacker who later obtains the application master key and the
 * (low-entropy, enumerable) IP + user-agent inputs. A stable, key-derived salt
 * would leave every historical hash brute-forceable forever; a disposable salt
 * makes the old data genuinely anonymous.
 * @api
 */
#[Api(since: '1.0.0')]
interface VisitorSaltStoreInterface
{
    /**
     * Return the salt for the given UTC day number, creating it atomically if
     * it does not exist yet.
     *
     * All requests within a single UTC day MUST observe the same salt, or one
     * visitor would hash to several ids and inflate unique counts; the
     * implementation therefore resolves the first-writer race to a single salt.
     * Use this for the CURRENT day, where a salt must exist.
     *
     * @return string A hex-encoded random salt (never empty).
     */
    public function saltForDay(int $dayNumber): string;

    /**
     * Return the salt for the given UTC day number, or null if none exists.
     *
     * Unlike {@see saltForDay()} this never creates one. Use it for a day whose
     * salt may already have been purged — yesterday during the midnight grace
     * window, or a historical day in a data-subject-access lookup. Fabricating a
     * salt for a purged day would resurrect an identifier the purge deliberately
     * destroyed.
     */
    public function existingSaltForDay(int $dayNumber): ?string;

    /**
     * Delete every salt whose day number is strictly less than the cutoff.
     *
     * This is what makes past days unlinkable. Callers must keep enough recent
     * days for the midnight grace window (today and yesterday at minimum).
     *
     * @return int Number of salts destroyed.
     */
    public function purgeOlderThan(int $cutoffDayNumber): int;
}
