<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Propagation;

use Pulsar\Api\Api;

use function array_keys;
use function array_map;
use function count;
use function explode;
use function implode;
use function rawurldecode;
use function rawurlencode;
use function str_contains;
use function strlen;
use function strstr;
use function trim;

/**
 * Parses and serializes W3C Baggage headers.
 *
 * Baggage format: `key1=value1;property1,key2=value2`
 * Values are URL-encoded. Properties (after `;`) are stripped on parse.
 *
 * Enforces W3C Baggage specification limits:
 * - Maximum 180 entries per header
 * - Maximum 128 bytes per key
 * - Maximum 256 bytes per value (after URL-decoding)
 * - Maximum 8192 bytes total header size
 *
 * @see https://www.w3.org/TR/baggage/
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class BaggageParser
{
    private const int MAX_ENTRIES = 180;
    private const int MAX_KEY_LENGTH = 128;
    private const int MAX_VALUE_LENGTH = 256;
    private const int MAX_HEADER_SIZE = 8192;

    /**
     * Parse a W3C Baggage header into key-value pairs.
     *
     * Properties (everything after `;` in each entry) are stripped.
     * Values are URL-decoded. Entries exceeding W3C size limits are skipped.
     *
     * @return array<string, string>
     */
    public function parse(string $header): array
    {
        $header = trim($header);

        if ($header === '' || strlen($header) > self::MAX_HEADER_SIZE) {
            return [];
        }

        $entries = explode(',', $header);
        $baggage = [];

        foreach ($entries as $entry) {
            if (count($baggage) >= self::MAX_ENTRIES) {
                break;
            }

            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            // Strip properties (everything after first `;`)
            if (str_contains($entry, ';')) {
                $beforeSemicolon = strstr($entry, ';', before_needle: true);

                if ($beforeSemicolon === false) {
                    continue;
                }

                $entry = $beforeSemicolon;
            }

            $parts = explode('=', $entry, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);

            if ($key === '' || strlen($key) > self::MAX_KEY_LENGTH) {
                continue;
            }

            $decoded = rawurldecode($value);

            if (strlen($decoded) > self::MAX_VALUE_LENGTH) {
                continue;
            }

            $baggage[$key] = $decoded;
        }

        return $baggage;
    }

    /**
     * Serialize key-value pairs into a W3C Baggage header string.
     *
     * Values are URL-encoded.
     *
     * @param array<string, string> $baggage
     */
    public function serialize(array $baggage): string
    {
        if ($baggage === []) {
            return '';
        }

        return implode(',', array_map(
            static fn(string $key, string $value): string => $key . '=' . rawurlencode($value),
            array_keys($baggage),
            $baggage,
        ));
    }
}
