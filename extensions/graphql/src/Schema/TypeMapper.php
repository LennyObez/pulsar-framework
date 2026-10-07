<?php

declare(strict_types=1);

namespace Pulsar\Extension\Graphql\Schema;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Content\ContentType;

/**
 * Maps CMS field types and content types to GraphQL type names.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TypeMapper
{
    /**
     * Map a CMS field type string to a GraphQL scalar type.
     */
    public static function mapFieldType(string $cmsFieldType): string
    {
        return match ($cmsFieldType) {
            'int', 'integer' => 'Int',
            'float', 'double', 'decimal' => 'Float',
            'bool', 'boolean' => 'Boolean',
            default => 'String',
        };
    }

    /**
     * Map a CMS content type enum to a GraphQL-safe type name suffix.
     */
    public static function mapContentType(ContentType $contentType): string
    {
        return match ($contentType) {
            ContentType::Article => 'Article',
            ContentType::Page => 'Page',
        };
    }
}
