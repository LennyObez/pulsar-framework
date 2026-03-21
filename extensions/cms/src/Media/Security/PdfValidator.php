<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Security;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Exception\CmsException;

use function fclose;
use function file_get_contents;
use function fopen;
use function fread;
use function is_file;
use function is_readable;
use function preg_match;
use function str_starts_with;

/**
 * Validates PDF files by checking magic bytes and scanning for
 * dangerous JavaScript, launch actions, and form submission patterns.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PdfValidator
{
    /**
     * Dangerous PDF patterns that indicate embedded scripting or external actions.
     *
     * @var list<string>
     */
    private const array DANGEROUS_PATTERNS = [
        '/\/JS\b/',
        '/\/JavaScript\b/',
        '/\/Launch\b/',
        '/\/SubmitForm\b/',
        '/\/GoToR\b/',
        '/\/OpenAction\b/',
        '/\/URI\b/',
        '/\/RichMedia\b/',
        '/\/ImportData\b/',
    ];

    /**
     * Validate a PDF file for safety.
     *
     * @param string $filePath Path to the PDF file
     *
     * @throws CmsException If the file is not a valid PDF or contains dangerous patterns
     */
    public function validate(string $filePath): void
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw CmsException::invalidPdfFile();
        }

        $handle = fopen($filePath, 'rb');

        if ($handle === false) {
            throw CmsException::invalidPdfFile();
        }

        $header = fread($handle, 5);
        fclose($handle);

        if ($header === false || !str_starts_with($header, '%PDF-')) {
            throw CmsException::invalidPdfFile();
        }

        $content = file_get_contents($filePath);

        if ($content === false) {
            throw CmsException::invalidPdfFile();
        }

        foreach (self::DANGEROUS_PATTERNS as $pattern) {
            if (preg_match($pattern, $content)) {
                throw CmsException::unsafePdfContent($pattern);
            }
        }
    }
}
