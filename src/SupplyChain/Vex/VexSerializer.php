<?php

declare(strict_types=1);

namespace Pulsar\SupplyChain\Vex;

use NoDiscard;
use Pulsar\Api\Internal;

use function array_filter;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Serializes VEX documents to the OpenVEX JSON format.
 *
 * Output conforms to the OpenVEX specification:
 * - @context: https://openvex.dev/ns/v0.2.0
 * - @id: document identifier
 * - author/tooling metadata
 * - Statements array with vulnerability, status, justification
 */
#[Internal(reason: 'Serialization implementation detail')]
final readonly class VexSerializer
{
    private const string OPENVEX_CONTEXT = 'https://openvex.dev/ns/v0.2.0';

    /**
     * Serialize a VEX document to OpenVEX JSON.
     *
     * @return string JSON-encoded OpenVEX document
     */
    #[NoDiscard]
    public function serialize(VexDocument $document): string
    {
        $statements = [];

        foreach ($document->statements as $statement) {
            $entry = [
                'vulnerability' => [
                    '@id' => $statement->vulnerability,
                    'name' => $statement->vulnerability,
                ],
                'products' => [
                    [
                        '@id' => $statement->product,
                    ],
                ],
                'status' => $statement->status->value,
            ];

            if ($statement->justification !== null) {
                $entry['justification'] = $statement->justification->value;
            }

            if ($statement->actionStatement !== '') {
                $entry['action_statement'] = $statement->actionStatement;
            }

            $statements[] = $entry;
        }

        $output = array_filter([
            '@context' => self::OPENVEX_CONTEXT,
            '@id' => $document->documentId,
            'author' => $document->tooling,
            'role' => 'document_author',
            'timestamp' => $document->timestamp->format('c'),
            'version' => $document->version,
            'tooling' => $document->tooling,
            'statements' => $statements,
        ], static fn(mixed $value): bool => $value !== '' && $value !== [] && $value !== 0);

        // version and statements should always be present even if zero/empty
        $output['version'] = $document->version;
        $output['statements'] = $statements;

        return json_encode($output, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
