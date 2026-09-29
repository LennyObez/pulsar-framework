<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Support;

use JsonException;
use RuntimeException;

use function array_key_exists;
use function file_get_contents;
use function hash_file;
use function hexdec;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_string;
use function json_decode;
use function ord;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function scandir;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function strlen;
use function strrpos;
use function substr;
use function trim;
use function unpack;

use const JSON_THROW_ON_ERROR;
use const PREG_SET_ORDER;

/**
 * Audits the bundled web fonts, over whatever checkout it is pointed at.
 *
 * WHAT THIS HALF CAN HONESTLY CHECK, AND WHAT IT CANNOT
 *
 * The defect that prompted all of this is invisible from PHP. `jetbrains-mono-variable.woff2`
 * shipped as a latin-ext subset slice — 190 codepoints, no digits, no a-z — and reading
 * that out of the file means decompressing a Brotli stream, which this toolchain's PHP has
 * no extension for. So the coverage half lives in tools/ci/verify-bundled-fonts.mjs, where
 * node:zlib provides Brotli with no dependency to install.
 *
 * That leaves a seam, and the seam is closed with hashes rather than trust. This class
 * proves the bytes on disk are exactly the bytes the manifest describes; the JavaScript
 * half proves those bytes cover what the manifest claims. Replace a font without updating
 * the manifest and this fails. Update the manifest to match a font nobody read and the
 * other half fails. Neither can bless a broken face alone, which is the only arrangement
 * where a split gate is worth as much as a whole one.
 *
 * The same seam now carries the `unicode-range` claims. Each face is split into one file
 * per script, and each is served by a rule that names the codepoints it covers; a rule
 * naming a wider range than its file carries makes the browser stop looking and paint the
 * fallback. Reading what a file actually covers still needs Brotli, so the halves divide
 * the sentence: this one proves the style sheet's range is exactly the range the manifest
 * records, and the JavaScript half proves the manifest's range is exactly the file's cmap.
 * Together they say the style sheet tells the truth about the bytes, and neither half can
 * say it alone.
 *
 * The WOFF2 parsing here is deliberately structural only — header, declared length, table
 * directory, compressed body — and reads no table tags. Teaching PHP the specification's
 * 63-entry tag table would put a second copy of it in the repository, and a second copy of
 * a fact is the thing this test directory exists to prevent. What each table holds is
 * read, once, by the half that can actually decompress it.
 */
final readonly class BundledFontAudit
{
    /** Where the manifest lives, relative to the checkout root. */
    private const string MANIFEST = 'resources/ui/fonts/fonts.manifest.json';

    /** The WOFF2 signature: 'wOF2'. */
    private const int SIGNATURE = 0x774F4632;

    /** A WOFF2 header is exactly this long before the table directory begins. */
    private const int HEADER_LENGTH = 48;

    /** The tag index that means 'the four-byte tag follows literally'. */
    private const int LITERAL_TAG = 0x3F;

    /** `glyf` and `loca` in the WOFF2 known-tag order; the two that invert the transform. */
    private const int TAG_GLYF = 10;

    private const int TAG_LOCA = 11;

    public function __construct(private string $root) {}

    /**
     * Everything wrong with this checkout's bundled fonts, from where PHP can see.
     *
     * @return list<FontFinding>
     */
    public function findings(): array
    {
        $manifestPath = $this->root . '/' . self::MANIFEST;

        if (!is_file($manifestPath)) {
            return [new FontFinding(
                'bundle/no-manifest',
                self::MANIFEST,
                'the font manifest is missing, so nothing states what the bundle must contain',
            )];
        }

        // A manifest that cannot be read is a finding rather than an exception: this is a
        // gate, and a gate that crashes tells a reader less than one that says what is
        // wrong with the file it was given.
        try {
            return $this->auditAgainst($this->manifest($manifestPath));
        } catch (JsonException | RuntimeException $failure) {
            return [new FontFinding('bundle/manifest-unreadable', self::MANIFEST, $failure->getMessage())];
        }
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return list<FontFinding>
     */
    private function auditAgainst(array $manifest): array
    {
        $fontDirectory = $this->root . '/' . self::string($manifest, 'fontDirectory');
        $styleSheet = $this->root . '/' . self::string($manifest, 'styleSheet');
        $declarations = self::fontFaces($styleSheet);

        $findings = [];
        $declared = [];

        foreach (self::families($manifest) as $family) {
            foreach ($this->licenceFindings($fontDirectory, $family) as $finding) {
                $findings[] = $finding;
            }

            foreach (self::children($family, 'faces', 'face') as $face) {
                foreach (self::children($face, 'subsets', 'subset') as $subset) {
                    $file = self::string($subset, 'file');
                    $declared[$file] = true;

                    foreach ($this->subsetFindings($fontDirectory, $family, $face, $subset, $declarations) as $finding) {
                        $findings[] = $finding;
                    }
                }
            }
        }

        foreach (self::woff2FilesIn($fontDirectory) as $file) {
            if (!array_key_exists($file, $declared)) {
                $findings[] = new FontFinding(
                    'bundle/undeclared-file',
                    $file,
                    'shipped in the font directory but absent from the manifest, so nothing '
                    . 'states what it must cover and nothing has read it',
                );
            }
        }

        foreach ($declarations as $declaration) {
            if (!array_key_exists($declaration['file'], $declared)) {
                $findings[] = new FontFinding(
                    'css/unbundled-src',
                    $declaration['file'],
                    'the style sheet serves this file, and the manifest does not describe it',
                );
            }
        }

        return $findings;
    }

    /**
     * The coverage half of the gate has to still be reachable.
     *
     * This is the one rule here that is about the gate rather than the fonts, and it is
     * the rule this repository has been burned by most: nine gates were found green
     * because nothing ran them. If the verifier is deleted, or vitest stops collecting
     * `.test.mjs`, the font coverage stops being checked by anything and every other
     * assertion in this file still passes — the bytes would match a manifest nobody
     * verifies any more.
     *
     * @return list<FontFinding>
     */
    public function wiringFindings(): array
    {
        $findings = [];

        foreach (['tools/ci/verify-bundled-fonts.mjs', 'tools/ci/verify-bundled-fonts.test.mjs'] as $path) {
            if (!is_file($this->root . '/' . $path)) {
                $findings[] = new FontFinding(
                    'wiring/verifier-missing',
                    $path,
                    'the half of this gate that reads what a font can actually draw is gone; '
                    . 'the hashes below would then pin bytes nobody verifies',
                );
            }
        }

        $config = $this->root . '/vitest.config.ts';
        $configured = is_file($config) ? (string) file_get_contents($config) : '';

        if (!str_contains($configured, '*.test.mjs')) {
            $findings[] = new FontFinding(
                'wiring/verifier-not-collected',
                'vitest.config.ts',
                'vitest no longer collects *.test.mjs, so the font coverage gate exists but '
                . 'never runs — which is indistinguishable from not having it',
            );
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $family
     *
     * @return list<FontFinding>
     */
    private function licenceFindings(string $fontDirectory, array $family): array
    {
        $name = self::string($family, 'family');
        $licence = self::map($family, 'license');
        $file = self::string($licence, 'file');
        $path = $fontDirectory . '/' . $file;

        if (!is_file($path)) {
            return [new FontFinding(
                'licence/missing',
                $name,
                sprintf(
                    '%s is not bundled. The OFL requires its text to travel with the font, and '
                    . 'this repository redistributes the font',
                    $file,
                ),
            )];
        }

        $findings = [];
        $digest = (string) hash_file('sha256', $path);

        if ($digest !== self::string($licence, 'sha256')) {
            $findings[] = new FontFinding(
                'licence/sha256',
                $name,
                sprintf('%s hashes to %s, the manifest records %s', $file, $digest, self::string($licence, 'sha256')),
            );
        }

        $text = (string) file_get_contents($path);

        if (!str_contains($text, 'SIL OPEN FONT LICENSE Version 1.1')) {
            $findings[] = new FontFinding(
                'licence/text',
                $name,
                $file . ' does not read like the SIL Open Font License 1.1',
            );
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $family
     * @param array<string, mixed> $face
     * @param array<string, mixed> $subset
     * @param list<array{family: string, file: string, style: string, weight: array{0: int, 1: int}|null, unicodeRange: string|null}> $declarations
     *
     * @return list<FontFinding>
     */
    private function subsetFindings(string $fontDirectory, array $family, array $face, array $subset, array $declarations): array
    {
        $file = self::string($subset, 'file');
        $path = $fontDirectory . '/' . $file;

        if (!is_file($path)) {
            return [new FontFinding(
                'bundle/missing-file',
                $file,
                self::string($family, 'family') . ' declares this subset but no such file is bundled',
            )];
        }

        $findings = [];
        $bytes = (string) file_get_contents($path);

        if (strlen($bytes) !== self::int($subset, 'bytes')) {
            $findings[] = new FontFinding(
                'bundle/bytes',
                $file,
                sprintf('the manifest records %d bytes, the file is %d', self::int($subset, 'bytes'), strlen($bytes)),
            );
        }

        $digest = (string) hash_file('sha256', $path);

        if ($digest !== self::string($subset, 'sha256')) {
            $findings[] = new FontFinding(
                'bundle/sha256',
                $file,
                sprintf(
                    'the file hashes to %s, the manifest records %s. Either the font was replaced '
                    . 'without re-verifying what it covers, or the manifest was edited to match a '
                    . 'font nobody read',
                    $digest,
                    self::string($subset, 'sha256'),
                ),
            );
        }

        $structural = self::structuralFailure($bytes);

        if ($structural !== null) {
            $findings[] = new FontFinding('font/structure', $file, $structural);
        }

        foreach ($this->styleSheetFindings($family, $face, $subset, $declarations) as $finding) {
            $findings[] = $finding;
        }

        return $findings;
    }

    /**
     * What the style sheet says about one bundled subset, against what the manifest says.
     *
     * @param array<string, mixed>           $family
     * @param array<string, mixed>           $face
     * @param array<string, mixed>           $subset
     * @param list<array{family: string, file: string, style: string, weight: array{0: int, 1: int}|null, unicodeRange: string|null}> $declarations
     *
     * @return list<FontFinding>
     */
    private function styleSheetFindings(array $family, array $face, array $subset, array $declarations): array
    {
        $file = self::string($subset, 'file');
        $declaration = null;

        foreach ($declarations as $candidate) {
            if ($candidate['file'] === $file) {
                $declaration = $candidate;

                break;
            }
        }

        if ($declaration === null) {
            return [new FontFinding(
                'css/unreferenced-face',
                $file,
                'bundled and described in the manifest, but no @font-face in the style sheet uses it',
            )];
        }

        $findings = [];

        if ($declaration['family'] !== self::string($family, 'family')) {
            $findings[] = new FontFinding(
                'css/family-name',
                $file,
                sprintf(
                    "the style sheet serves this file as '%s', the manifest calls it '%s'",
                    $declaration['family'],
                    self::string($family, 'family'),
                ),
            );
        }

        if ($declaration['style'] !== self::string($face, 'style')) {
            $findings[] = new FontFinding(
                'css/font-style',
                $file,
                sprintf(
                    'the style sheet declares font-style: %s, the manifest says %s',
                    $declaration['style'],
                    self::string($face, 'style'),
                ),
            );
        }

        foreach (self::unicodeRangeFindings($file, $subset, $declaration['unicodeRange']) as $finding) {
            $findings[] = $finding;
        }

        $weight = $declaration['weight'];
        $axes = self::map($face, 'axes');

        if ($weight === null || !array_key_exists('wght', $axes)) {
            return $findings;
        }

        $axis = self::map($axes, 'wght');

        if ($weight[0] < self::int($axis, 'min') || $weight[1] > self::int($axis, 'max')) {
            $findings[] = new FontFinding(
                'css/axis-range',
                $file,
                sprintf(
                    'the style sheet promises font-weight %d-%d, the manifest records a weight axis '
                    . 'spanning %d-%d. The weights outside it are synthesised, not drawn',
                    $weight[0],
                    $weight[1],
                    self::int($axis, 'min'),
                    self::int($axis, 'max'),
                ),
            );
        }

        return $findings;
    }

    /**
     * The half of the unicode-range claim PHP can settle.
     *
     * A rule that names a wider range than its file carries is the defect the split
     * introduced: the browser believes the rule, stops looking, finds no glyph and paints
     * the fallback — the original silent-fallback failure, moved from the font into the
     * style sheet. Reading what a file really covers needs Brotli, which this toolchain's
     * PHP does not have; what it can settle is that the style sheet and the manifest name
     * the same range, and tools/ci/verify-bundled-fonts.mjs settles that the manifest's
     * range is the file's cmap. Neither statement is worth anything without the other.
     *
     * The comparison is on the codepoints named, not on the text: Prettier is free to
     * break a thousand-character value across lines, and a gate that failed on where the
     * formatter put a newline would be switched off within a week.
     *
     * @param array<string, mixed> $subset
     *
     * @return list<FontFinding>
     */
    private static function unicodeRangeFindings(string $file, array $subset, ?string $declared): array
    {
        $recorded = $subset['unicodeRange'] ?? null;

        if (!is_string($recorded) || $recorded === '') {
            return [new FontFinding(
                'bundle/no-unicode-range',
                $file,
                'the manifest describes this subset without recording the range it covers, so '
                . 'nothing states which characters the style sheet may claim for it',
            )];
        }

        if ($declared === null) {
            return [new FontFinding(
                'css/missing-unicode-range',
                $file,
                'the style sheet serves this subset without a unicode-range, so every reader '
                . 'downloads it whatever their page says — which is the whole cost the split removed',
            )];
        }

        if (self::normaliseRange($declared) === self::normaliseRange($recorded)) {
            return [];
        }

        return [new FontFinding(
            'css/unicode-range',
            $file,
            sprintf(
                'the style sheet claims %s and the manifest records %s. One of the two is '
                . 'describing a file nobody read',
                self::abbreviate($declared),
                self::abbreviate($recorded),
            ),
        )];
    }

    /**
     * A unicode-range value reduced to the ranges it names, in one spelling.
     */
    private static function normaliseRange(string $value): string
    {
        $matches = [];
        preg_match_all('/U\+([0-9A-Fa-f?]+)(?:-([0-9A-Fa-f]+))?/', $value, $matches, PREG_SET_ORDER);

        $spans = [];

        foreach ($matches as $match) {
            $from = str_replace('?', '0', $match[1]);
            $to = $match[2] ?? str_replace('?', 'F', $match[1]);
            $spans[] = sprintf('%X-%X', (int) hexdec($from), (int) hexdec($to));
        }

        return implode(',', $spans);
    }

    private static function abbreviate(string $value): string
    {
        $flat = (string) preg_replace('/\s+/', ' ', trim($value));

        return strlen($flat) > 90 ? substr($flat, 0, 87) . '...' : $flat;
    }

    /**
     * Reads a WOFF2 header and table directory, and says what is wrong with them.
     *
     * @return string|null the failure, or null when the file is structurally sound
     */
    private static function structuralFailure(string $bytes): ?string
    {
        $length = strlen($bytes);

        if ($length < self::HEADER_LENGTH) {
            return sprintf('the file is %d bytes; a WOFF2 header alone is %d', $length, self::HEADER_LENGTH);
        }

        if (self::uint32($bytes, 0) !== self::SIGNATURE) {
            return sprintf("the signature is 0x%08x, not 'wOF2' (0x774f4632)", self::uint32($bytes, 0));
        }

        $declared = self::uint32($bytes, 8);

        if ($declared !== $length) {
            return sprintf(
                'the header declares %d bytes, the file is %d — truncated, padded or corrupt',
                $declared,
                $length,
            );
        }

        $numTables = self::uint16($bytes, 12);

        if ($numTables === 0) {
            return 'the table directory is empty';
        }

        $offset = self::HEADER_LENGTH;

        for ($index = 0; $index < $numTables; $index++) {
            if ($offset >= $length) {
                return sprintf('the table directory ends after %d of %d entries', $index, $numTables);
            }

            $flags = ord($bytes[$offset]);
            $offset++;
            $tagIndex = $flags & 0x3F;

            // A tag index of 63 means the four-byte tag follows literally instead.
            if ($tagIndex === self::LITERAL_TAG) {
                $offset += 4;
            }

            $original = self::base128($bytes, $offset);

            if ($original === null) {
                return sprintf('the length of table %d is not a valid UIntBase128', $index);
            }

            $offset = $original;

            // Whether a transformed length follows is not the same question for every
            // table. glyf and loca invert the convention: for them transform version 0 is
            // the transform and version 3 is the null transform, while every other table
            // reads the other way round. This used to be waved through on the grounds that
            // the tag was not worth decoding, and it was wrong — fontTools writes both
            // tables transformed, so the walk lost sync at glyf and every entry after it
            // was read out of the middle of a length field. The directory still ended at a
            // plausible-looking offset, which is exactly why nothing noticed.
            //
            // Only the two indices that invert are needed, not the specification's whole
            // 63-entry tag table: what a table holds is still read by the half of the gate
            // that can decompress it.
            $transformVersion = ($flags >> 6) & 0x03;
            $inverts = $tagIndex === self::TAG_GLYF || $tagIndex === self::TAG_LOCA;
            $isTransformed = $inverts ? $transformVersion === 0 : $transformVersion !== 0;

            if ($isTransformed) {
                $transformed = self::base128($bytes, $offset);

                if ($transformed === null) {
                    return sprintf('the transformed length of table %d is not a valid UIntBase128', $index);
                }

                $offset = $transformed;
            }
        }

        $compressed = self::uint32($bytes, 20);

        if ($offset + $compressed > $length) {
            return sprintf(
                'the compressed body runs past the end of the file: %d + %d > %d',
                $offset,
                $compressed,
                $length,
            );
        }

        if ($compressed === 0) {
            return 'the compressed body is empty, so the file carries no tables at all';
        }

        return null;
    }

    /**
     * Skips one base-128 variable-length integer and returns the offset after it.
     *
     * The value is not needed — only whether the directory is well formed — so this
     * validates rather than decodes.
     */
    private static function base128(string $bytes, int $offset): ?int
    {
        $length = strlen($bytes);

        for ($index = 0; $index < 5; $index++) {
            if ($offset >= $length) {
                return null;
            }

            $byte = ord($bytes[$offset]);
            $offset++;

            // A leading zero byte would let one number be written two ways, which the
            // specification forbids so that a directory has exactly one encoding.
            if ($index === 0 && $byte === 0x80) {
                return null;
            }

            if (($byte & 0x80) === 0) {
                return $offset;
            }
        }

        return null;
    }

    /**
     * The @font-face rules a style sheet declares, reduced to the claims they make.
     *
     * @return list<array{family: string, file: string, style: string, weight: array{0: int, 1: int}|null, unicodeRange: string|null}>
     */
    private static function fontFaces(string $styleSheet): array
    {
        if (!is_file($styleSheet)) {
            return [];
        }

        $css = (string) file_get_contents($styleSheet);
        $blocks = [];
        preg_match_all('/@font-face\s*\{([^}]*)\}/', $css, $blocks, PREG_SET_ORDER);

        $declarations = [];

        foreach ($blocks as $block) {
            $body = $block[1];
            $family = [];
            $source = [];

            if (
                preg_match('/font-family:\s*[\'"]?([^;\'"]+)[\'"]?\s*;/', $body, $family) !== 1
                || preg_match('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/', $body, $source) !== 1
            ) {
                continue;
            }

            $path = str_replace('\\', '/', $source[1]);
            $file = substr($path, (int) strrpos($path, '/') + 1);
            $style = [];
            $weight = [];
            $unicode = [];
            $range = null;

            // A single value means one weight rather than a range, so both ends are it.
            if (preg_match('/font-weight:\s*(\d+)(?:\s+(\d+))?\s*;/', $body, $weight) === 1) {
                $range = [(int) $weight[1], (int) ($weight[2] ?? $weight[1])];
            }

            $declarations[] = [
                'family' => trim($family[1]),
                'file' => $file,
                'style' => preg_match('/font-style:\s*([a-z]+)\s*;/', $body, $style) === 1
                    ? $style[1]
                    : 'normal',
                'weight' => $range,
                // Prettier may put a long value on its own line, so the range runs to
                // the semicolon rather than to the end of the line it started on.
                'unicodeRange' => preg_match('/unicode-range:\s*([^;]+);/', $body, $unicode) === 1
                    ? trim($unicode[1])
                    : null,
            ];
        }

        return $declarations;
    }

    /**
     * @return list<string>
     */
    private static function woff2FilesIn(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach (scandir($directory) ?: [] as $entry) {
            if (str_ends_with($entry, '.woff2')) {
                $files[] = $entry;
            }
        }

        return $files;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException  when the file is not JSON
     * @throws RuntimeException when it is JSON but not an object
     */
    private function manifest(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new RuntimeException('the font manifest is not a JSON object');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return list<array<string, mixed>>
     */
    private static function families(array $manifest): array
    {
        $families = $manifest['families'] ?? null;

        if (!is_array($families)) {
            throw new RuntimeException('the font manifest declares no families');
        }

        $typed = [];

        foreach ($families as $family) {
            if (!is_array($family)) {
                throw new RuntimeException('a family entry in the font manifest is not an object');
            }

            /** @var array<string, mixed> $family */
            $typed[] = $family;
        }

        return $typed;
    }

    /**
     * The list of objects at a key, or a stated reason the manifest cannot be walked.
     *
     * A face is a family at one style, and its subsets are the files that make it up, so
     * the walk is two levels deep where it used to be one. Both levels have the same
     * shape, and describing that shape twice is how the two copies start disagreeing.
     *
     * @param array<string, mixed> $parent
     * @param string               $key    the key holding the list
     * @param string               $what   what one entry is called, for the message
     *
     * @return list<array<string, mixed>>
     */
    private static function children(array $parent, string $key, string $what): array
    {
        $entries = $parent[$key] ?? null;

        if (!is_array($entries)) {
            throw new RuntimeException(sprintf("the font manifest has no '%s' list where one is required", $key));
        }

        $typed = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException(sprintf('a %s entry in the font manifest is not an object', $what));
            }

            /** @var array<string, mixed> $entry */
            $typed[] = $entry;
        }

        return $typed;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function map(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (!is_array($value)) {
            throw new RuntimeException(sprintf("the font manifest has no object at '%s'", $key));
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (!is_string($value)) {
            throw new RuntimeException(sprintf("the font manifest has no string at '%s'", $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (!is_int($value)) {
            throw new RuntimeException(sprintf("the font manifest has no integer at '%s'", $key));
        }

        return $value;
    }

    private static function uint32(string $bytes, int $offset): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', substr($bytes, $offset, 4));

        return $unpacked[1];
    }

    private static function uint16(string $bytes, int $offset): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('n', substr($bytes, $offset, 2));

        return $unpacked[1];
    }
}
