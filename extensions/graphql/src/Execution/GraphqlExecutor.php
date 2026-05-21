<?php

declare(strict_types=1);

namespace Pulsar\Extension\Graphql\Execution;

use Pulsar\Api\Api;
use Pulsar\Extension\Graphql\Resolver\ContentResolver;
use Pulsar\Extension\Graphql\Resolver\MediaResolver;
use Pulsar\Extension\Graphql\Resolver\TaxonomyResolver;
use Pulsar\Extension\Graphql\Schema\Schema;
use Throwable;

use function array_key_exists;
use function is_array;

/**
 * Executes parsed GraphQL queries against the schema and resolvers.
 *
 * Walks the AST, invokes the appropriate resolver for each root field,
 * and projects the requested selection set onto the resolved data.
 *
 * Security limits: queries exceeding {@see MAX_DEPTH} nesting levels
 * or {@see MAX_FIELDS} total selected fields are rejected before execution.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class GraphqlExecutor
{
    /** Maximum allowed nesting depth of selection sets. */
    private const int MAX_DEPTH = 15;

    /** Maximum allowed total number of selected fields in a query. */
    private const int MAX_FIELDS = 500;

    private GraphqlParser $parser;

    public function __construct(
        private Schema $schema,
        private ContentResolver $contentResolver,
        private TaxonomyResolver $taxonomyResolver,
        private MediaResolver $mediaResolver,
    ) {
        $this->parser = new GraphqlParser();
    }

    /**
     * Execute a GraphQL query string and return the result.
     *
     * @param array<string, string|int|float|bool|null> $variables
     * @return array{data: array<string, mixed>|null, errors: list<array<string, mixed>>}
     */
    public function execute(string $query, array $variables = []): array
    {
        $errors = [];
        $data = null;

        try {
            $parsed = $this->parser->parse($query, $variables);

            $fieldCount = 0;
            $this->validateComplexity($parsed->fields, 1, $fieldCount);

            $data = [];

            foreach ($parsed->fields as $field) {
                $key = $field->responseKey();

                try {
                    $data[$key] = $this->resolveRootField($field);
                } catch (Throwable $e) {
                    $errors[] = [
                        'message' => $e->getMessage(),
                        'path' => [$key],
                    ];
                    $data[$key] = null;
                }
            }
        } catch (GraphqlException $e) {
            $errors[] = ['message' => $e->getMessage()];
        }

        return [
            'data' => $data,
            'errors' => $errors,
        ];
    }

    /**
     * Validate that the parsed query does not exceed depth or field-count limits.
     *
     * @param list<ParsedField> $fields
     * @throws GraphqlException When limits are exceeded
     */
    private function validateComplexity(array $fields, int $currentDepth, int &$fieldCount): void
    {
        if ($currentDepth > self::MAX_DEPTH) {
            throw GraphqlException::queryTooComplex('Maximum query depth exceeded');
        }

        foreach ($fields as $field) {
            $fieldCount++;

            if ($fieldCount > self::MAX_FIELDS) {
                throw GraphqlException::queryTooComplex('Too many fields requested');
            }

            if ($field->selections !== []) {
                $this->validateComplexity($field->selections, $currentDepth + 1, $fieldCount);
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function resolveRootField(ParsedField $field): ?array
    {
        return match ($field->name) {
            'content' => $this->resolveContent($field),
            'contents' => $this->resolveContents($field),
            'taxonomy' => $this->resolveTaxonomy($field),
            'media' => $this->resolveMedia($field),
            default => throw GraphqlException::validationError("Unknown root field: $field->name"),
        };
    }

    /** @return array<string, mixed>|null */
    private function resolveContent(ParsedField $field): ?array
    {
        $id = $this->requireArgument($field, 'id');
        $data = $this->contentResolver->resolveById((string) $id);

        if ($data === null) {
            return null;
        }

        return $this->projectSelections($data, $field->selections, 'Content');
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveContents(ParsedField $field): array
    {
        $locale = (string) $this->requireArgument($field, 'locale');
        $type = isset($field->arguments['type']) ? (string) $field->arguments['type'] : null;
        $page = isset($field->arguments['page']) ? (int) $field->arguments['page'] : 1;
        $perPage = isset($field->arguments['perPage']) ? (int) $field->arguments['perPage'] : 20;

        $data = $this->contentResolver->resolveList($locale, $type, $page, $perPage);

        return $this->projectSelections($data, $field->selections, 'ContentConnection');
    }

    /** @return array<string, mixed>|null */
    private function resolveTaxonomy(ParsedField $field): ?array
    {
        $slug = (string) $this->requireArgument($field, 'slug');
        $data = $this->taxonomyResolver->resolveBySlug($slug);

        if ($data === null) {
            return null;
        }

        return $this->projectSelections($data, $field->selections, 'Taxonomy');
    }

    /** @return array<string, mixed>|null */
    private function resolveMedia(ParsedField $field): ?array
    {
        $id = $this->requireArgument($field, 'id');
        $data = $this->mediaResolver->resolveById((string) $id);

        if ($data === null) {
            return null;
        }

        return $this->projectSelections($data, $field->selections, 'Media');
    }

    /**
     * Project the requested selection set onto resolved data.
     *
     * @param array<string, mixed> $data The resolved data map
     * @param list<ParsedField> $selections The requested fields
     * @param string $typeName The GraphQL type name for sub-field resolution
     * @return array<string, mixed>
     */
    private function projectSelections(array $data, array $selections, string $typeName): array
    {
        if ($selections === []) {
            return $data;
        }

        $result = [];

        foreach ($selections as $sel) {
            $key = $sel->responseKey();
            $fieldName = $sel->name;

            if (array_key_exists($fieldName, $data)) {
                /** @var mixed $value */
                $value = $data[$fieldName];

                // Handle list of items (e.g., ContentConnection.items)
                if (is_array($value) && $this->isIndexedList($value) && $sel->selections !== []) {
                    $itemType = $this->resolveListItemType($typeName, $fieldName);
                    $projected = [];

                    /** @var mixed $item */
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            /** @var array<string, mixed> $item */
                            $projected[] = $this->projectSelections($item, $sel->selections, $itemType);
                        }
                    }

                    $result = [...$result, $key => $projected];
                } elseif (is_array($value) && $sel->selections !== [] && !$this->isIndexedList($value)) {
                    // Nested object
                    $nestedType = $this->resolveNestedType($typeName, $fieldName);
                    /** @var array<string, mixed> $value */
                    $result = [...$result, $key => $this->projectSelections($value, $sel->selections, $nestedType)];
                } else {
                    $result = [...$result, $key => $value];
                }
            } else {
                // Lazy-resolved sub-fields (translations, blocks, terms)
                $result = [...$result, $key => $this->resolveLazyField($data, $typeName, $sel)];
            }
        }

        return $result;
    }

    /**
     * Resolve fields that require additional repository calls.
     *
     * @param array<string, mixed> $data
     * @return array<int|string, mixed>|null
     */
    private function resolveLazyField(array $data, string $typeName, ParsedField $field): ?array
    {
        if ($typeName === 'Content' && $field->name === 'translations') {
            /** @var string $contentId */
            $contentId = $data['id'];
            $translations = $this->contentResolver->resolveTranslations($contentId);

            if ($field->selections === []) {
                return $translations;
            }

            $result = [];

            foreach ($translations as $t) {
                $result[] = $this->projectSelections($t, $field->selections, 'Translation');
            }

            return $result;
        }

        if ($typeName === 'Content' && $field->name === 'blocks') {
            /** @var string $contentId */
            $contentId = $data['id'];
            $locale = isset($field->arguments['locale']) ? (string) $field->arguments['locale'] : '';

            if ($locale === '') {
                throw GraphqlException::validationError('blocks field requires a "locale" argument');
            }

            $blocks = $this->contentResolver->resolveBlocks($contentId, $locale);

            if ($field->selections === []) {
                return $blocks;
            }

            $result = [];

            foreach ($blocks as $b) {
                $result[] = $this->projectSelections($b, $field->selections, 'ContentBlock');
            }

            return $result;
        }

        if ($typeName === 'Taxonomy' && $field->name === 'terms') {
            /** @var string $taxonomyId */
            $taxonomyId = $data['id'];
            $locale = isset($field->arguments['locale']) ? (string) $field->arguments['locale'] : '';

            if ($locale === '') {
                throw GraphqlException::validationError('terms field requires a "locale" argument');
            }

            $terms = $this->taxonomyResolver->resolveTerms($taxonomyId, $locale);

            if ($field->selections === []) {
                return $terms;
            }

            $result = [];

            foreach ($terms as $t) {
                $result[] = $this->projectSelections($t, $field->selections, 'TaxonomyTerm');
            }

            return $result;
        }

        return null;
    }

    private function requireArgument(ParsedField $field, string $name): string|int|float|bool|null
    {
        if (!array_key_exists($name, $field->arguments)) {
            throw GraphqlException::validationError(
                "Missing required argument '$name' on field '$field->name'",
            );
        }

        return $field->arguments[$name];
    }

    /**
     * @param array<mixed> $value
     */
    private function isIndexedList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_key_exists(0, $value);
    }

    private function resolveListItemType(string $parentType, string $fieldName): string
    {
        $type = $this->schema->getType($parentType);

        if ($type !== null && isset($type->fields[$fieldName])) {
            return $type->fields[$fieldName]->type;
        }

        return 'String';
    }

    private function resolveNestedType(string $parentType, string $fieldName): string
    {
        return $this->resolveListItemType($parentType, $fieldName);
    }
}
