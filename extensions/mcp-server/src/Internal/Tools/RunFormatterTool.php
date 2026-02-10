<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Internal\Tools;

use function is_string;

use Pulsar\Api\Internal;
use Pulsar\Extension\McpServer\Contracts\McpAccessGateInterface;
use Pulsar\Extension\McpServer\Contracts\McpToolInterface;
use Pulsar\Extension\McpServer\Domain\ToolCategory;
use Pulsar\Extension\McpServer\Domain\ToolResult;
use Pulsar\Extension\McpServer\Internal\Security\ParamValidator;
use Pulsar\Extension\McpServer\Internal\Subprocess\SubprocessRunner;

#[Internal]
final readonly class RunFormatterTool implements McpToolInterface
{
    public function __construct(
        private SubprocessRunner $runner,
        private McpAccessGateInterface $accessGate,
        private string $composerBinary,
        private string $pnpmBinary,
        private string $projectRoot,
    ) {}

    public function name(): string
    {
        return 'pulsar.formatter.run';
    }

    public function description(): string
    {
        return 'Run code formatters (PHP-CS-Fixer or Prettier)';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => [
                    'type' => 'string',
                    'enum' => ['php', 'js'],
                    'description' => 'Formatter type: php (PHP-CS-Fixer) or js (Prettier)',
                ],
                'path' => ['type' => 'string', 'description' => 'Optional path to format'],
            ],
            'required' => ['type'],
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
        $type = ParamValidator::validateType((string) ($params['type'] ?? ''));

        $this->accessGate->assertConcurrencyAllowed();

        try {
            $command = match ($type) {
                'php' => [$this->composerBinary, 'cs:fix', '--no-interaction', '--no-ansi'],
                'js' => [$this->pnpmBinary, 'format:fix'],
            };

            // Validate and handle optional path for PHP formatter
            $path = $params['path'] ?? null;
            if ($type === 'php' && is_string($path) && $path !== '') {
                $validated = ParamValidator::validatePath($path, $this->projectRoot);

                if ($validated !== null) {
                    $this->accessGate->assertPathAllowed($path);
                    // PHP-CS-Fixer accepts path via --path-mode=override and positional arg
                    $command[] = '--';
                    $command[] = $validated;
                }
            }

            return $this->runner->run($command);
        } finally {
            $this->accessGate->releaseConcurrencySlot();
        }
    }
}
