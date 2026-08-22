<?php

declare(strict_types=1);

namespace Pulsar\AI\Pipeline;

use NoDiscard;
use Pulsar\AI\AiClientInterface;
use Pulsar\AI\AiResponse;
use Pulsar\AI\ChatMessage;
use Pulsar\AI\Config\AiRequestOptions;
use Pulsar\AI\ToolCall;
use Pulsar\AI\ToolDefinition;
use Pulsar\Api\Api;
use Throwable;

use function array_key_exists;
use function sprintf;

/**
 * Tool calling pipeline with automatic execution loop.
 *
 * Registers callable tools, sends them to the AI model, and automatically
 * executes tool calls until the model produces a final text response.
 *
 * Includes a configurable iteration limit to prevent infinite loops.
 * @api
 */
#[Api(since: '1.0.0')]
final class ToolCalling
{
    /**
     * @var array<string, callable(array<string, mixed>): string>
     */
    private array $handlers = [];

    /**
     * @var list<ToolDefinition>
     */
    private array $definitions = [];

    public function __construct(
        private readonly AiClientInterface $client,
        private readonly int $maxIterations = 10,
    ) {}

    /**
     * Register a tool with its handler function.
     *
     * @param callable(array<string, mixed>): string $handler
     */
    public function register(ToolDefinition $definition, callable $handler): self
    {
        $this->definitions[] = $definition;
        $this->handlers[$definition->name] = $handler;

        return $this;
    }

    /**
     * Run the tool-calling loop until the model produces a final answer.
     *
     * @param list<ChatMessage> $messages Initial conversation messages
     * @return ToolCallingResult
     */
    #[NoDiscard]
    public function run(
        array $messages,
        AiRequestOptions $options = new AiRequestOptions(),
    ): ToolCallingResult {
        $allMessages = $messages;
        /** @var list<ToolCallExecution> $executions */
        $executions = [];
        $iterations = 0;

        $requestOptions = new AiRequestOptions(
            temperature: $options->temperature,
            maxTokens: $options->maxTokens,
            systemPrompt: $options->systemPrompt,
            model: $options->model,
            timeoutSeconds: $options->timeoutSeconds,
            tools: $this->definitions,
        );

        while ($iterations < $this->maxIterations) {
            $iterations++;

            $response = $this->client->chat($allMessages, $requestOptions);

            if ($response->isError() || !$response->hasToolCalls()) {
                return new ToolCallingResult(
                    response: $response,
                    executions: $executions,
                    iterations: $iterations,
                );
            }

            // Add assistant's response to conversation
            $allMessages[] = ChatMessage::assistant($response->content);

            // Execute each tool call
            foreach ($response->toolCalls as $toolCall) {
                $result = $this->executeToolCall($toolCall);
                $executions[] = $result;

                $allMessages[] = ChatMessage::toolResult(
                    $toolCall->id,
                    $result->output,
                );
            }
        }

        return new ToolCallingResult(
            response: AiResponse::error(sprintf('Tool calling exceeded max iterations (%d)', $this->maxIterations)),
            executions: $executions,
            iterations: $iterations,
        );
    }

    private function executeToolCall(ToolCall $toolCall): ToolCallExecution
    {
        if (!array_key_exists($toolCall->name, $this->handlers)) {
            return new ToolCallExecution(
                toolCall: $toolCall,
                output: sprintf('Error: Unknown tool "%s"', $toolCall->name),
                succeeded: false,
            );
        }

        try {
            $output = ($this->handlers[$toolCall->name])($toolCall->arguments);

            return new ToolCallExecution(
                toolCall: $toolCall,
                output: $output,
                succeeded: true,
            );
        } catch (Throwable $e) {
            return new ToolCallExecution(
                toolCall: $toolCall,
                output: sprintf('Error executing tool "%s": %s', $toolCall->name, $e->getMessage()),
                succeeded: false,
            );
        }
    }
}
