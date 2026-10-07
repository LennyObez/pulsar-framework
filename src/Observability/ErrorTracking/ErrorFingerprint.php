<?php

declare(strict_types=1);

namespace Pulsar\Observability\ErrorTracking;

use NoDiscard;
use Pulsar\Api\Api;
use Throwable;

use function hash;
use function preg_replace;
use function sprintf;
use function str_replace;
use function strrpos;
use function substr;

/**
 * Deterministic fingerprint for error grouping.
 *
 * Produces a sha256 hash of `{class}|{normalised message}|{normalised file}|{line}`
 * to group identical errors regardless of when they occur.
 *
 * Neither the message nor the file path may enter the hash raw, because
 * both are unstable in the wild:
 *
 *  - `getMessage()` typically embeds user-controlled values (record ids,
 *    URLs, free text), so a raw message mints a fresh fingerprint per
 *    input and grouping by error class never coalesces on an aggregator.
 *  - `getFile()` is an absolute path that varies between CI agents,
 *    container images, and local checkouts, so the *same* error would
 *    fingerprint differently on staging and on production.
 *
 * Both are therefore normalised before hashing:
 *
 *  - message: strip numeric / hex / quoted-string / UUID tokens.
 *  - file: strip the longest common project root (`..../src/Foo.php` →
 *    `src/Foo.php`) and force forward slashes so Windows + Linux agree.
 *
 * Changing either normalisation changes every fingerprint, which splits
 * already-grouped errors on downstream aggregators.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ErrorFingerprint
{
    public function __construct(
        public string $value,
    ) {}

    /**
     * Generate a fingerprint from a throwable.
     */
    #[NoDiscard]
    public static function fromThrowable(Throwable $throwable): self
    {
        $input = sprintf(
            '%s|%s|%s|%d',
            $throwable::class,
            self::normaliseMessage($throwable->getMessage()),
            self::normaliseFile($throwable->getFile()),
            $throwable->getLine(),
        );

        return new self(hash('sha256', $input));
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Replace user-controlled tokens with placeholders so two errors of
     * the same class differing only in payload still hash to the same
     * fingerprint.
     *
     * Order matters: UUID before hex (UUID matches hex with dashes),
     * quoted strings before numbers (so `"42"` collapses to `<str>` not
     * `"<num>"`).
     */
    private static function normaliseMessage(string $message): string
    {
        $patterns = [
            // UUID v1-5
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i' => '<uuid>',
            // Quoted strings (single + double).
            '/"(?:\\\\.|[^"\\\\])*"/' => '<str>',
            "/'(?:\\\\.|[^'\\\\])*'/" => '<str>',
            // Hex tokens of length 16+ (hashes, ids).
            '/\b[0-9a-f]{16,}\b/i' => '<hex>',
            // Decimal numbers (including negatives + decimals).
            '/-?\b\d+(?:\.\d+)?\b/' => '<num>',
        ];

        $normalised = $message;

        foreach ($patterns as $pattern => $replacement) {
            $normalised = preg_replace($pattern, $replacement, $normalised) ?? $normalised;
        }

        return $normalised;
    }

    /**
     * Strip everything up to and including the project source root so
     * `/var/www/app/src/Foo.php` and `D:\dev\app\src\Foo.php` both
     * collapse to `src/Foo.php`. We anchor on `/src/`, `/tests/`,
     * `/extensions/` and `/vendor/` because those are the only path
     * fragments shared between CI / staging / prod / dev workstations.
     */
    private static function normaliseFile(string $file): string
    {
        $unixPath = str_replace('\\', '/', $file);

        $anchors = ['/src/', '/tests/', '/extensions/', '/vendor/'];

        foreach ($anchors as $anchor) {
            $position = strrpos($unixPath, $anchor);

            if ($position !== false) {
                return substr($unixPath, $position + 1);
            }
        }

        return $unixPath;
    }
}
