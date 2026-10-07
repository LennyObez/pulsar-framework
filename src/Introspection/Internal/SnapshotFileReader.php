<?php

declare(strict_types=1);

namespace Pulsar\Introspection\Internal;

use JsonException;
use Pulsar\Api\Internal;
use Pulsar\Introspection\Data\ApiSnapshotData;

use function file_get_contents;
use function is_array;
use function is_file;
use function json_decode;
use function json_validate;

use const JSON_THROW_ON_ERROR;

/**
 * Reads the public API snapshot from tools/api/public-api.snapshot.json.
 *
 * If the file is missing or invalid, returns empty data with a warning
 * rather than throwing an exception: introspection degrades gracefully.
 */
#[Internal]
final readonly class SnapshotFileReader
{
    public function __construct(
        private string $projectRoot,
    ) {}

    /**
     * Read and parse the API snapshot file.
     *
     * @param list<string> $warnings
     */
    public function read(array &$warnings): ApiSnapshotData
    {
        $path = $this->projectRoot . '/tools/api/public-api.snapshot.json';

        if (!is_file($path)) {
            $warnings[] = 'API snapshot file not found at tools/api/public-api.snapshot.json: API snapshot section is empty.';

            return new ApiSnapshotData();
        }

        $content = file_get_contents($path);

        if ($content === false) {
            $warnings[] = 'Failed to read API snapshot file: API snapshot section is empty.';

            return new ApiSnapshotData();
        }

        if (!json_validate($content)) {
            $warnings[] = 'API snapshot file contains invalid JSON: API snapshot section is empty.';

            return new ApiSnapshotData();
        }

        try {
            $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $warnings[] = 'Failed to decode API snapshot JSON: ' . $e->getMessage();

            return new ApiSnapshotData();
        }

        if (!is_array($data)) {
            $warnings[] = 'API snapshot file root is not an object: API snapshot section is empty.';

            return new ApiSnapshotData();
        }

        /** @var array<string, mixed> $data */
        $classes = $data['api_classes'] ?? [];

        if (!is_array($classes)) {
            $warnings[] = 'API snapshot "api_classes" is not an object: API snapshot section is empty.';

            return new ApiSnapshotData();
        }

        /** @var array<string, array{since: string, methods: list<string>, constants: list<string>}> $classes */
        return new ApiSnapshotData(classes: $classes);
    }
}
