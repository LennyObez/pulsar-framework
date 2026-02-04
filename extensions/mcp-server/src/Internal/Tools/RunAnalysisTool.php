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

#[Internal]
final readonly class RunAnalysisTool implements McpToolInterface
{
    public function __construct(
        private SubprocessRunner $runner,
        private McpAccessGateInterface $accessGate,
        private string $composerBinary,
    ) {}

    public function name(): string
    {
        return 'pulsar.analysis.run';
    }

    public function description(): string
    {
        return 'Run static analysis (PHPStan or Psalm)';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'analyzer' => [
                    'type' => 'string',
                    'enum' => ['phpstan', 'psalm'],
                    'description' => 'Which analyzer to run',
                ],
            ],
            'required' => ['analyzer'],
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
        $analyzer = ParamValidator::validateAnalyzer((string) ($params['analyzer'] ?? ''));

        $this->accessGate->assertConcurrencyAllowed();

        try {
            $command = [$this->composerBinary, $analyzer, '--no-interaction', '--no-ansi'];

            return $this->runner->run($command);
        } finally {
            $this->accessGate->releaseConcurrencySlot();
        }
    }
}
