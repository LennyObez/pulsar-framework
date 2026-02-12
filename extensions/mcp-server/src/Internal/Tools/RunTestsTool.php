<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Internal\Tools;

use Pulsar\Api\Internal;
use Pulsar\Extension\McpServer\Contracts\McpAccessGateInterface;
use Pulsar\Extension\McpServer\Contracts\McpToolInterface;
use Pulsar\Extension\McpServer\Domain\ToolCategory;
use Pulsar\Extension\McpServer\Domain\ToolResult;
use Pulsar\Extension\McpServer\Internal\Security\ParamValidator;
use Pulsar\Extension\McpServer\Internal\Subprocess\SubprocessRunner;

use function is_string;

#[Internal]
final readonly class RunTestsTool implements McpToolInterface
{
    public function __construct(
        private SubprocessRunner $runner,
        private McpAccessGateInterface $accessGate,
        private string $phpunitBinary,
        private string $projectRoot,
    ) {}

    public function name(): string
    {
        return 'pulsar.tests.run';
    }

    public function description(): string
    {
        return 'Run PHPUnit tests with the project configuration';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'filter' => ['type' => 'string', 'description' => 'Test name filter (--filter)'],
                'path' => ['type' => 'string', 'description' => 'Test file or directory path'],
            ],
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'exitCode' => ['type' => 'integer'],
                'stdout' => ['type' => 'string'],
                'stderr' => ['type' => 'string'],
                'timedOut' => ['type' => 'boolean'],
                'wasCancelled' => ['type' => 'boolean'],
                'truncated' => ['type' => 'boolean'],
            ],
        ];
    }

    public function category(): ToolCategory
    {
        return ToolCategory::Action;
    }

    public function execute(array $params): ToolResult
    {
        $this->accessGate->assertConcurrencyAllowed();

        try {
            $command = [$this->phpunitBinary, '-c', 'tools/php/phpunit.xml', '--no-interaction'];

            // Validate and append filter
            $filter = $params['filter'] ?? null;
            if (is_string($filter) && $filter !== '') {
                $filter = ParamValidator::validateFilter($filter);
                $command[] = '--filter';
                $command[] = $filter;
            }

            // Validate and append path
            $path = $params['path'] ?? null;
            if (is_string($path) && $path !== '') {
                $validated = ParamValidator::validatePath($path, $this->projectRoot);

                if ($validated !== null) {
                    $this->accessGate->assertPathAllowed($path);
                    $command[] = $validated;
                }
            }

            /** @var list<string> $command */
            return $this->runner->run($command);
        } finally {
            $this->accessGate->releaseConcurrencySlot();
        }
    }
}
