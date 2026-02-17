<?php

declare(strict_types=1);

namespace App\Entity;

use Pulsar\Extension\Orm\Attribute\Column;
use Pulsar\Extension\Orm\Attribute\Id;
use Pulsar\Extension\Orm\Attribute\Table;
use Pulsar\Extension\Orm\Attribute\Timestamps;
use Pulsar\Extension\Orm\Domain\ColumnType;

/**
 * Article entity mapped to the `articles` table.
 *
 * Demonstrates the Pulsar ORM attribute-based mapping:
 * - #[Table] maps the class to a database table
 * - #[Id] marks the primary key (auto-increment by default)
 * - #[Column] maps properties to columns with type hints
 * - #[Timestamps] adds created_at/updated_at tracking
 */
#[Table('articles')]
#[Timestamps]
final class Article
{
    #[Id]
    #[Column(type: ColumnType::Integer)]
    public int $id;

    #[Column(type: ColumnType::String, length: 255)]
    public string $title;

    #[Column(type: ColumnType::Text)]
    public string $body;

    #[Column(type: ColumnType::String, length: 100)]
    public string $author;

    #[Column(type: ColumnType::Boolean)]
    public bool $published = false;

    #[Column(type: ColumnType::String, nullable: true)]
    public ?string $createdAt = null;

    #[Column(type: ColumnType::String, nullable: true)]
    public ?string $updatedAt = null;

    public function __construct(
        string $title = '',
        string $body = '',
        string $author = '',
        bool $published = false,
    ) {
        $this->title = $title;
        $this->body = $body;
        $this->author = $author;
        $this->published = $published;
    }
}
