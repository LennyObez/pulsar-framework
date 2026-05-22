<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Resume\ResumePdfGenerator;
use Pulsar\Http\Message\Response;

use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Controller serving a print-friendly HTML view of a resume.
 *
 * The generated page is optimized for browser print-to-PDF with
 * proper page breaks and print media styles.
 */
#[Internal(reason: 'CMS controller; implementation detail')]
final readonly class ResumePdfController
{
    private const string SQL_FIND_RESUME = <<<'SQL'
        SELECT c.id, ct.title
        FROM cms_contents c
        JOIN cms_content_translations ct ON ct.content_id = c.id AND ct.locale = :locale
        WHERE ct.slug_segment = :slug
          AND c.content_type = 'resume'
          AND c.deleted_at IS NULL
          AND ct.status = 'published'
        LIMIT 1
        SQL;

    private const string SQL_FIELD_VALUES = <<<'SQL'
        SELECT ftf.field_key, fv.value_string, fv.value_json
        FROM cms_content_field_values fv
        JOIN cms_content_type_fields ftf ON ftf.id = fv.field_id
        WHERE fv.content_id = :content_id
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    /**
     * GET /resume/{slug}/print: Print-friendly HTML page for PDF export.
     */
    public function printView(ServerRequestInterface $request, string $slug): Response
    {
        /** @var mixed $rawLocale */
        $rawLocale = $request->getQueryParams()['locale'] ?? 'en';
        $locale = is_string($rawLocale) ? $rawLocale : 'en';

        $result = $this->connection->query(self::SQL_FIND_RESUME, [
            'slug' => $slug,
            'locale' => $locale,
        ]);

        $row = $result->first();

        if ($row === null) {
            return Response::json(['error' => 'Resume not found', 'status' => 404], 404);
        }

        $contentId = $row->getString('id');
        $name = $row->getString('title');

        $fieldResult = $this->connection->query(self::SQL_FIELD_VALUES, [
            'content_id' => $contentId,
        ]);

        /** @var array<string, mixed> $resumeData */
        $resumeData = [];

        foreach ($fieldResult->map(static fn(Row $r): array => [
            'key' => $r->getString('field_key'),
            'value_string' => $r->getNullableString('value_string'),
            'value_json' => $r->getNullableString('value_json'),
        ]) as $field) {
            $jsonValue = $field['value_json'];

            if (is_string($jsonValue) && $jsonValue !== '') {
                $resumeData = [...$resumeData, $field['key'] => json_decode($jsonValue, true, flags: JSON_THROW_ON_ERROR)];
            } elseif ($field['value_string'] !== null) {
                $resumeData = [...$resumeData, $field['key'] => $field['value_string']];
            }
        }

        $html = ResumePdfGenerator::generatePrintHtml($name, $resumeData);

        return new Response(
            statusCode: 200,
            headers: ['Content-Type' => 'text/html; charset=UTF-8'],
            body: $html,
        );
    }
}
