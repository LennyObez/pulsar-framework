<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\AI;

use Pulsar\Api\Api;

use function str_replace;

/**
 * Immutable prompt template with named placeholders for LLM prompts.
 *
 * Placeholders use `{name}` syntax and are replaced during rendering.
 */
#[Api(since: '1.0.0')]
final readonly class PromptTemplate
{
    public function __construct(
        public string $name,
        public string $template,
        public string $systemPrompt,
        public float $defaultTemperature,
        public int $defaultMaxTokens,
    ) {}

    /**
     * Render the template by replacing placeholders with values.
     *
     * @param array<string, string|int|float> $variables
     */
    public function render(array $variables): string
    {
        $result = $this->template;

        foreach ($variables as $key => $value) {
            $result = str_replace("{{$key}}", (string) $value, $result);
        }

        return $result;
    }
}
