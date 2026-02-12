<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Hygiene;

use Pulsar\Api\Internal;

use function array_flip;
use function array_intersect_key;
use function session_status;

use const PHP_SESSION_ACTIVE;

/**
 * Resets PHP superglobals between requests in persistent runtimes.
 */
#[Internal]
final class SuperglobalResetter
{
    /** @var list<string> Server keys to preserve (process-level, not request-level) */
    private const array PRESERVED_SERVER_KEYS = [
        'SERVER_SOFTWARE',
        'SERVER_NAME',
        'SERVER_ADDR',
        'SERVER_PORT',
        'DOCUMENT_ROOT',
        'SCRIPT_FILENAME',
        'PHP_SELF',
        'argv',
        'argc',
    ];

    public function reset(): void
    {
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
        $_REQUEST = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
        }

        // Preserve process-level server vars, clear request-level ones
        $_SERVER = array_intersect_key(
            $_SERVER,
            array_flip(self::PRESERVED_SERVER_KEYS),
        );
    }
}
