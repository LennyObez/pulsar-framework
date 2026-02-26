<?php

declare(strict_types=1);

namespace Pulsar\Extension\Graphql\Schema;

use Pulsar\Api\Api;

/**
 * Builds the GraphQL schema programmatically from CMS domain types.
 */
#[Api(since: '1.0.0')]
final readonly class SchemaBuilder
{
    /**
     * Build the complete schema with all CMS types.
     */
    public function build(): Schema
    {
        $types = [];

        $contentType = $this->buildContentType();
        $types[$contentType->name] = $contentType;

        $translationType = $this->buildTranslationType();
        $types[$translationType->name] = $translationType;

        $blockType = $this->buildContentBlockType();
        $types[$blockType->name] = $blockType;

        $taxonomyType = $this->buildTaxonomyType();
        $types[$taxonomyType->name] = $taxonomyType;

        $termType = $this->buildTaxonomyTermType();
        $types[$termType->name] = $termType;

        $mediaType = $this->buildMediaType();
        $types[$mediaType->name] = $mediaType;

        $connectionType = $this->buildContentConnectionType();
        $types[$connectionType->name] = $connectionType;

        $queryType = $this->buildQueryType();
        $types[$queryType->name] = $queryType;

        return new Schema($queryType, $types);
    }

    private function buildContentType(): ObjectType
    {
        return new ObjectType('Content', [
            'id' => new FieldDefinition('id', 'ID', nonNull: true),
            'tenantId' => new FieldDefinition('tenantId', 'ID'),
            'contentType' => new FieldDefinition('contentType', 'String', nonNull: true),
            'authorId' => new FieldDefinition('authorId', 'ID', nonNull: true),
            'status' => new FieldDefinition('status', 'String', nonNull: true),
            'template' => new FieldDefinition('template', 'String'),
            'parentId' => new FieldDefinition('parentId', 'ID'),
            'sortOrder' => new FieldDefinition('sortOrder', 'Int', nonNull: true),
            'commentPolicy' => new FieldDefinition('commentPolicy', 'String', nonNull: true),
            'publishedAt' => new FieldDefinition('publishedAt', 'String'),
            'createdAt' => new FieldDefinition('createdAt', 'String', nonNull: true),
            'updatedAt' => new FieldDefinition('updatedAt', 'String', nonNull: true),
            'translations' => new FieldDefinition('translations', 'Translation', isList: true, listItemNonNull: true),
            'blocks' => new FieldDefinition(
                'blocks',
                'ContentBlock',
                isList: true,
                listItemNonNull: true,
                arguments: [
                    'locale' => new ArgumentDefinition('locale', 'String', nonNull: true),
                ],
            ),
        ]);
    }

    private function buildTranslationType(): ObjectType
    {
        return new ObjectType('Translation', [
            'id' => new FieldDefinition('id', 'ID', nonNull: true),
            'contentId' => new FieldDefinition('contentId', 'ID', nonNull: true),
            'locale' => new FieldDefinition('locale', 'String', nonNull: true),
            'title' => new FieldDefinition('title', 'String', nonNull: true),
            'slugSegment' => new FieldDefinition('slugSegment', 'String', nonNull: true),
            'path' => new FieldDefinition('path', 'String', nonNull: true),
            'body' => new FieldDefinition('body', 'String', nonNull: true),
            'excerpt' => new FieldDefinition('excerpt', 'String'),
            'metaTitle' => new FieldDefinition('metaTitle', 'String'),
            'metaDescription' => new FieldDefinition('metaDescription', 'String'),
            'readingTimeMinutes' => new FieldDefinition('readingTimeMinutes', 'Int'),
        ]);
    }

    private function buildContentBlockType(): ObjectType
    {
        return new ObjectType('ContentBlock', [
            'id' => new FieldDefinition('id', 'ID', nonNull: true),
            'contentId' => new FieldDefinition('contentId', 'ID', nonNull: true),
            'locale' => new FieldDefinition('locale', 'String', nonNull: true),
            'blockType' => new FieldDefinition('blockType', 'String', nonNull: true),
            'sortOrder' => new FieldDefinition('sortOrder', 'Int', nonNull: true),
            'data' => new FieldDefinition('data', 'String', nonNull: true),
        ]);
    }

    private function buildTaxonomyType(): ObjectType
    {
        return new ObjectType('Taxonomy', [
            'id' => new FieldDefinition('id', 'ID', nonNull: true),
            'tenantId' => new FieldDefinition('tenantId', 'ID'),
            'slug' => new FieldDefinition('slug', 'String', nonNull: true),
            'hierarchical' => new FieldDefinition('hierarchical', 'Boolean', nonNull: true),
            'createdAt' => new FieldDefinition('createdAt', 'String', nonNull: true),
            'terms' => new FieldDefinition(
                'terms',
                'TaxonomyTerm',
                isList: true,
                listItemNonNull: true,
                arguments: [
                    'locale' => new ArgumentDefinition('locale', 'String', nonNull: true),
                ],
            ),
        ]);
    }

    private function buildTaxonomyTermType(): ObjectType
    {
        return new ObjectType('TaxonomyTerm', [
            'id' => new FieldDefinition('id', 'ID', nonNull: true),
            'taxonomyId' => new FieldDefinition('taxonomyId', 'ID', nonNull: true),
            'parentId' => new FieldDefinition('parentId', 'ID'),
            'sortOrder' => new FieldDefinition('sortOrder', 'Int', nonNull: true),
            'createdAt' => new FieldDefinition('createdAt', 'String', nonNull: true),
        ]);
    }

    private function buildMediaType(): ObjectType
    {
        return new ObjectType('Media', [
            'id' => new FieldDefinition('id', 'ID', nonNull: true),
            'tenantId' => new FieldDefinition('tenantId', 'ID'),
            'filename' => new FieldDefinition('filename', 'String', nonNull: true),
            'mimeType' => new FieldDefinition('mimeType', 'String', nonNull: true),
            'fileSize' => new FieldDefinition('fileSize', 'Int', nonNull: true),
            'width' => new FieldDefinition('width', 'Int'),
            'height' => new FieldDefinition('height', 'Int'),
            'altTextDefault' => new FieldDefinition('altTextDefault', 'String'),
            'visibility' => new FieldDefinition('visibility', 'String', nonNull: true),
            'createdAt' => new FieldDefinition('createdAt', 'String', nonNull: true),
        ]);
    }

    private function buildContentConnectionType(): ObjectType
    {
        return new ObjectType('ContentConnection', [
            'items' => new FieldDefinition('items', 'Content', nonNull: true, isList: true, listItemNonNull: true),
            'totalCount' => new FieldDefinition('totalCount', 'Int', nonNull: true),
            'page' => new FieldDefinition('page', 'Int', nonNull: true),
            'perPage' => new FieldDefinition('perPage', 'Int', nonNull: true),
        ]);
    }

    private function buildQueryType(): ObjectType
    {
        return new ObjectType('Query', [
            'content' => new FieldDefinition(
                'content',
                'Content',
                arguments: [
                    'id' => new ArgumentDefinition('id', 'ID', nonNull: true),
                ],
            ),
            'contents' => new FieldDefinition(
                'contents',
                'ContentConnection',
                arguments: [
                    'locale' => new ArgumentDefinition('locale', 'String', nonNull: true),
                    'type' => new ArgumentDefinition('type', 'String'),
                    'page' => new ArgumentDefinition('page', 'Int', defaultValue: 1),
                    'perPage' => new ArgumentDefinition('perPage', 'Int', defaultValue: 20),
                ],
            ),
            'taxonomy' => new FieldDefinition(
                'taxonomy',
                'Taxonomy',
                arguments: [
                    'slug' => new ArgumentDefinition('slug', 'String', nonNull: true),
                ],
            ),
            'media' => new FieldDefinition(
                'media',
                'Media',
                arguments: [
                    'id' => new ArgumentDefinition('id', 'ID', nonNull: true),
                ],
            ),
        ]);
    }
}
