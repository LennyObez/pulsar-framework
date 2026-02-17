<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Document;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;

use function fclose;
use function fgets;
use function file_exists;
use function filesize;
use function fopen;
use function is_resource;
use function is_string;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

/**
 * Extracts metadata from PDF documents.
 *
 * Reads PDF metadata from the file's Info dictionary and cross-reference
 * table without loading the entire file into memory. For robust extraction
 * of all PDF versions, uses a stream-based parser that reads the file
 * sequentially.
 */
#[Internal(reason: 'Use DocumentMetadata DTO directly for public API')]
final readonly class DocumentMetadataExtractor
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function extract(string $filePath): DocumentMetadata
    {
        if (!file_exists($filePath)) {
            return new DocumentMetadata();
        }

        $content = $this->readFileHead($filePath, 65536);

        if ($content === null) {
            return new DocumentMetadata();
        }

        $pdfVersion = $this->extractPdfVersion($content);

        if ($pdfVersion === null) {
            $this->logger->warning('File does not appear to be a PDF', [
                'file' => $filePath,
            ]);

            return new DocumentMetadata();
        }

        // Read the full file for metadata extraction (capped at 2 MB for safety)
        $fullContent = $this->readFileHead($filePath, 2 * 1024 * 1024);

        if ($fullContent === null) {
            return new DocumentMetadata(pdfVersion: $pdfVersion);
        }

        $info = $this->extractInfoDictionary($fullContent);
        $pageCount = $this->countPages($fullContent);
        $size = filesize($filePath);

        return new DocumentMetadata(
            title: $info['title'],
            author: $info['author'],
            subject: $info['subject'],
            creator: $info['creator'],
            producer: $info['producer'],
            pageCount: $pageCount,
            creationDate: $info['creationDate'],
            modificationDate: $info['modificationDate'],
            pdfVersion: $pdfVersion,
            fileSize: $size !== false ? $size : null,
        );
    }

    private function readFileHead(string $filePath, int $maxBytes): ?string
    {
        $handle = @fopen($filePath, 'rb');

        if (!is_resource($handle)) {
            $this->logger->warning('Cannot open file for metadata extraction', [
                'file' => $filePath,
            ]);

            return null;
        }

        $content = '';
        $remaining = $maxBytes;

        while ($remaining > 0) {
            $chunk = fgets($handle, min($remaining + 1, 8192));

            if ($chunk === false) {
                break;
            }

            $content .= $chunk;
            $remaining -= strlen($chunk);
        }

        fclose($handle);

        return $content;
    }

    private function extractPdfVersion(string $content): ?string
    {
        if (preg_match('/^%PDF-(\d+\.\d+)/m', $content, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @return array{title: ?string, author: ?string, subject: ?string, creator: ?string, producer: ?string, creationDate: ?string, modificationDate: ?string}
     */
    private function extractInfoDictionary(string $content): array
    {
        $result = [
            'title' => null,
            'author' => null,
            'subject' => null,
            'creator' => null,
            'producer' => null,
            'creationDate' => null,
            'modificationDate' => null,
        ];

        $result['title'] = $this->extractPdfString($content, '/Title');
        $result['author'] = $this->extractPdfString($content, '/Author');
        $result['subject'] = $this->extractPdfString($content, '/Subject');
        $result['creator'] = $this->extractPdfString($content, '/Creator');
        $result['producer'] = $this->extractPdfString($content, '/Producer');
        $result['creationDate'] = $this->normalizePdfDate(
            $this->extractPdfString($content, '/CreationDate'),
        );
        $result['modificationDate'] = $this->normalizePdfDate(
            $this->extractPdfString($content, '/ModDate'),
        );

        return $result;
    }

    /**
     * Extract a PDF string value following a key like /Title.
     *
     * Handles both literal strings in parentheses and hex strings in angle brackets.
     */
    private function extractPdfString(string $content, string $key): ?string
    {
        $pos = strpos($content, $key);

        if ($pos === false) {
            return null;
        }

        $afterKey = substr($content, $pos + strlen($key));
        $afterKey = ltrim($afterKey);

        // Literal string: (...)
        if (str_starts_with($afterKey, '(')) {
            return $this->parseLiteralString($afterKey);
        }

        // Hex string: <...>
        if (str_starts_with($afterKey, '<')) {
            return $this->parseHexString($afterKey);
        }

        return null;
    }

    private function parseLiteralString(string $data): ?string
    {
        $depth = 0;
        $result = '';
        $escaped = false;
        $len = strlen($data);

        for ($i = 0; $i < $len; $i++) {
            $char = $data[$i];

            if ($escaped) {
                $result .= $char;
                $escaped = false;

                continue;
            }

            if ($char === '\\') {
                $escaped = true;

                continue;
            }

            if ($char === '(') {
                $depth++;

                if ($depth > 1) {
                    $result .= $char;
                }

                continue;
            }

            if ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    break;
                }

                $result .= $char;

                continue;
            }

            if ($depth > 0) {
                $result .= $char;
            }
        }

        $trimmed = trim($result);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function parseHexString(string $data): ?string
    {
        $end = strpos($data, '>');

        if ($end === false) {
            return null;
        }

        $hex = substr($data, 1, $end - 1);
        $hex = preg_replace('/\s+/', '', $hex);

        if (!is_string($hex) || $hex === '') {
            return null;
        }

        $decoded = @hex2bin($hex);

        if (!is_string($decoded)) {
            return null;
        }

        $trimmed = trim($decoded);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Count PDF pages by searching for /Type /Page entries (not /Pages).
     */
    private function countPages(string $content): ?int
    {
        // Look for /Type /Pages /Count N pattern first (most reliable)
        if (preg_match('/\/Type\s*\/Pages\b.*?\/Count\s+(\d+)/s', $content, $matches) === 1) {
            return (int) $matches[1];
        }

        // Fallback: count /Type /Page occurrences (not /Pages)
        $count = preg_match_all('/\/Type\s*\/Page\b(?!s)/', $content);

        return $count > 0 ? $count : null;
    }

    /**
     * Normalize a PDF date string (D:YYYYMMDDHHmmSS) to ISO 8601.
     */
    private function normalizePdfDate(?string $pdfDate): ?string
    {
        if ($pdfDate === null) {
            return null;
        }

        // Strip the D: prefix
        $date = $pdfDate;

        if (str_starts_with($date, 'D:')) {
            $date = substr($date, 2);
        }

        // Parse: YYYYMMDDHHmmSS+HH'mm' or YYYYMMDD
        if (preg_match('/^(\d{4})(\d{2})?(\d{2})?(\d{2})?(\d{2})?(\d{2})?/', $date, $m) !== 1) {
            return null;
        }

        $year = $m[1];
        $month = $m[2] ?? '01';
        $day = $m[3] ?? '01';
        $hour = $m[4] ?? '00';
        $minute = $m[5] ?? '00';
        $second = $m[6] ?? '00';

        return sprintf('%s-%s-%sT%s:%s:%s', $year, $month, $day, $hour, $minute, $second);
    }
}
