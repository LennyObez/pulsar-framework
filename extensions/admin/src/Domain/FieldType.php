<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Domain;

use Pulsar\Api\Api;

/**
 * Supported field types for admin resource definitions.
 *
 * Provides 32 field types covering all common admin form needs:
 * text inputs, rich content, temporal pickers, media, structured
 * data, and specialized UI controls.
 */
#[Api(since: '1.0.0')]
enum FieldType: string
{
    // --- Text & Content ---
    case String = 'string';
    case Text = 'text';
    case RichText = 'rich_text';
    case Markdown = 'markdown';
    case Code = 'code';
    case Slug = 'slug';
    case Password = 'password';

    // --- Numeric ---
    case Integer = 'integer';
    case Float = 'float';
    case Rating = 'rating';

    // --- Boolean & Choice ---
    case Boolean = 'boolean';
    case Toggle = 'toggle';
    case Enum = 'enum';
    case Tags = 'tags';

    // --- Temporal ---
    case Date = 'date';
    case DateTime = 'datetime';
    case Time = 'time';

    // --- Contact & Identity ---
    case Email = 'email';
    case Url = 'url';
    case Phone = 'phone';

    // --- Visual ---
    case Color = 'color';
    case Icon = 'icon';

    // --- Media & Files ---
    case FileUpload = 'file_upload';
    case Image = 'image';

    // --- Structured Data ---
    case Json = 'json';
    case KeyValue = 'key_value';
    case Repeater = 'repeater';

    // --- Relations ---
    case Relation = 'relation';
    case MorphRelation = 'morph_relation';

    // --- Layout & Metadata ---
    case Hidden = 'hidden';
    case Computed = 'computed';

    /**
     * Whether this field type is rendered as a form input.
     */
    public function isEditable(): bool
    {
        return match ($this) {
            self::Computed, self::Hidden => false,
            default => true,
        };
    }

    /**
     * Whether this field type supports full-text search indexing.
     */
    public function isSearchable(): bool
    {
        return match ($this) {
            self::String, self::Text, self::RichText, self::Markdown,
            self::Slug, self::Email, self::Url, self::Tags => true,
            default => false,
        };
    }

    /**
     * Whether this field type supports sorting in list views.
     */
    public function isSortable(): bool
    {
        return match ($this) {
            self::String, self::Integer, self::Float, self::Boolean,
            self::Toggle, self::Date, self::DateTime, self::Time,
            self::Email, self::Slug, self::Rating, self::Enum => true,
            default => false,
        };
    }
}
