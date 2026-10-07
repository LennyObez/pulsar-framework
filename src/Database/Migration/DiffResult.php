<?php

declare(strict_types=1);

namespace Pulsar\Database\Migration;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Result of a schema diff operation.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DiffResult
{
    /**
     * @param string $version    Migration version timestamp
     * @param string $content    Full migration file content
     * @param list<string> $upStatements   SQL statements for up()
     * @param list<string> $downStatements SQL statements for down()
     * @param bool $hasChanges   Whether any differences were found
     */
    public function __construct(
        public string $version,
        public string $content,
        public array $upStatements,
        public array $downStatements,
        public bool $hasChanges,
    ) {}

    #[NoDiscard]
    public static function noChanges(): self
    {
        return new self(
            version: '',
            content: '',
            upStatements: [],
            downStatements: [],
            hasChanges: false,
        );
    }
}
