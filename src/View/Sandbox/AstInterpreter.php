<?php

declare(strict_types=1);

namespace Pulsar\View\Sandbox;

use Pulsar\Api\Internal;
use Pulsar\View\ViewException;

use function array_key_exists;
use function htmlspecialchars;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_iterable;
use function is_string;
use function microtime;
use function preg_match;
use function strlen;
use function trim;

/**
 * AST interpreter for untrusted templates.
 *
 * Walks the parsed AST tree, evaluating nodes with deterministic resource bounds.
 * NEVER compiles to PHP. All output is auto-escaped with no bypass.
 */
#[Internal(reason: 'Interpreter internals are an engine implementation detail')]
final class AstInterpreter
{
    private int $stepCount = 0;

    private int $outputSize = 0;

    private float $startTime = 0.0;

    private string $output = '';

    public function __construct(
        private readonly SandboxConfig $config,
        private readonly ?TranslationCallback $translationCallback = null,
    ) {}

    /**
     * Interpret an AST tree with the given data and return the rendered output.
     *
     * @param AstNode $root The root AST node
     * @param array<string, mixed> $data Template variables
     *
     * @throws ViewException If any resource limit is exceeded
     */
    public function interpret(AstNode $root, array $data): string
    {
        $this->stepCount = 0;
        $this->outputSize = 0;
        $this->output = '';
        $this->startTime = microtime(true);

        $this->evaluateChildren($root->children, $data);

        return $this->output;
    }

    /**
     * Evaluate a list of child nodes.
     *
     * @param list<AstNode> $nodes
     * @param array<string, mixed> $data
     */
    private function evaluateChildren(array $nodes, array $data): void
    {
        foreach ($nodes as $node) {
            $this->evaluateNode($node, $data);
        }
    }

    /**
     * Evaluate a single AST node.
     *
     * @param array<string, mixed> $data
     */
    private function evaluateNode(AstNode $node, array $data): void
    {
        $this->incrementStep();

        match ($node->type) {
            AstNodeType::Text => $this->appendOutput($node->value),
            AstNodeType::Output => $this->evaluateOutput($node, $data),
            AstNodeType::If => $this->evaluateIf($node, $data),
            AstNodeType::Foreach => $this->evaluateForeach($node, $data),
            AstNodeType::Include => $this->evaluateInclude($node, $data),
            AstNodeType::I18n => $this->evaluateI18n($node, $data),
            AstNodeType::Root => $this->evaluateChildren($node->children, $data),
        };
    }

    /**
     * Evaluate an output node ({{ $var }}).
     * All output is auto-escaped — no raw bypass.
     *
     * @param array<string, mixed> $data
     */
    private function evaluateOutput(AstNode $node, array $data): void
    {
        $value = $this->resolveExpression($node->value, $data);
        $stringValue = match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? '1' : '',
            $value === null => '',
            default => '',
        };
        $escaped = htmlspecialchars($stringValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $this->appendOutput($escaped);
    }

    /**
     * Evaluate an @if block.
     *
     * @param array<string, mixed> $data
     */
    private function evaluateIf(AstNode $node, array $data): void
    {
        $condition = $this->resolveExpression($node->value, $data);

        if ($condition) {
            $this->evaluateChildren($node->children, $data);
        } else {
            $this->evaluateChildren($node->elseChildren, $data);
        }
    }

    /**
     * Evaluate a @foreach block.
     *
     * @param array<string, mixed> $data
     */
    private function evaluateForeach(AstNode $node, array $data): void
    {
        // Parse "items as item" or "items as key => item"
        $parts = $this->parseForeachExpression($node->value);

        if ($parts === null) {
            throw ViewException::invalidDirective('foreach', 'invalid expression: ' . $node->value);
        }

        $collection = $this->resolveExpression($parts['collection'], $data);

        if (!is_iterable($collection)) {
            return;
        }

        $iterationCount = 0;

        foreach ($collection as $key => $value) {
            $iterationCount++;

            if ($iterationCount > $this->config->loopLimit) {
                throw ViewException::sandboxLoopLimitExceeded($this->config->loopLimit);
            }

            $loopData = $data;
            $loopData[$parts['value']] = $value;

            if ($parts['key'] !== null) {
                $loopData[$parts['key']] = $key;
            }

            $this->evaluateChildren($node->children, $loopData);
        }
    }

    /**
     * Evaluate an @include directive (template IDs only, no file paths).
     *
     * @param array<string, mixed> $data
     */
    private function evaluateInclude(AstNode $node, array $data): void
    {
        $templateId = $this->resolveStringLiteral($node->value);

        if (!$this->config->isIncludeAllowed($templateId)) {
            throw ViewException::includeNotAllowed($templateId);
        }

        $content = $this->config->getIncludeContent($templateId);

        if ($content === null) {
            return;
        }

        // Parse and interpret the included template using the same sandbox
        $parser = new AstParser();
        $ast = $parser->parse($content);
        $this->evaluateChildren($ast->children, $data);
    }

    /**
     * Evaluate an @i18n directive.
     *
     * @param array<string, mixed> $data
     */
    private function evaluateI18n(AstNode $node, array $data): void
    {
        $key = $this->resolveStringLiteral($node->value);

        if ($this->translationCallback !== null) {
            $translated = ($this->translationCallback)($key, $data);
        } else {
            $translated = $key;
        }

        $escaped = htmlspecialchars($translated, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $this->appendOutput($escaped);
    }

    /**
     * Resolve a simple expression against the data context.
     *
     * Supports:
     * - Simple variables: $name
     * - Dot notation: $user.name
     * - String literals: 'text'
     * - Numeric literals: 42, 3.14
     * - Boolean literals: true, false
     * - Null: null
     * - Negation: !$var
     * - Comparison: $a == $b, $a != $b, $a > $b, etc.
     */
    /**
     * @param array<string, mixed> $data
     */
    private function resolveExpression(string $expression, array $data): mixed
    {
        $expr = trim($expression);

        // Negation
        if (str_starts_with($expr, '!')) {
            return !$this->resolveExpression(substr($expr, 1), $data);
        }

        // Comparisons
        foreach (['===', '!==', '==', '!=', '>=', '<=', '>', '<'] as $op) {
            $pos = strpos($expr, " {$op} ");

            if ($pos !== false) {
                $left = $this->resolveExpression(substr($expr, 0, $pos), $data);
                $right = $this->resolveExpression(substr($expr, $pos + strlen($op) + 2), $data);

                return match ($op) {
                    '===' => $left === $right,
                    '!==' => $left !== $right,
                    '==' => $left == $right,
                    '!=' => $left != $right,
                    '>=' => $left >= $right,
                    '<=' => $left <= $right,
                    '>' => $left > $right,
                    '<' => $left < $right,
                };
            }
        }

        // String literal (opening and closing quotes must match)
        if (preg_match('/^"(.*)"\s*$/s', $expr, $m) === 1) {
            return $m[1];
        }

        if (preg_match("/^'(.*)'\\s*\$/s", $expr, $m) === 1) {
            return $m[1];
        }

        // Numeric
        if (is_numeric($expr)) {
            return str_contains($expr, '.') ? (float) $expr : (int) $expr;
        }

        // Boolean/null
        return match (strtolower($expr)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => $this->resolveVariable($expr, $data),
        };
    }

    /**
     * Resolve a variable reference from data, supporting dot notation.
     */
    /**
     * @param array<string, mixed> $data
     */
    private function resolveVariable(string $name, array $data): mixed
    {
        // Strip $ prefix
        $key = ltrim($name, '$');

        // Dot notation: $user.name → $data['user']['name']
        $parts = explode('.', $key);
        $current = $data;

        foreach ($parts as $part) {
            if (is_array($current) && array_key_exists($part, $current)) {
                $current = $current[$part];
            } else {
                return null;
            }
        }

        return $current;
    }

    /**
     * Extract a string literal from an expression (strips quotes).
     */
    private function resolveStringLiteral(string $expression): string
    {
        $trimmed = trim($expression);

        if (
            (str_starts_with($trimmed, "'") && str_ends_with($trimmed, "'"))
            || (str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"'))
        ) {
            return substr($trimmed, 1, -1);
        }

        return $trimmed;
    }

    /**
     * Parse a foreach expression into its parts.
     *
     * @return array{collection: string, key: ?string, value: string}|null
     */
    private function parseForeachExpression(string $expression): ?array
    {
        // Match: $items as $item
        if (preg_match('/^(.+?)\s+as\s+\$(\w+)$/', trim($expression), $m) === 1) {
            return ['collection' => trim($m[1]), 'key' => null, 'value' => $m[2]];
        }

        // Match: $items as $key => $item
        if (preg_match('/^(.+?)\s+as\s+\$(\w+)\s*=>\s*\$(\w+)$/', trim($expression), $m) === 1) {
            return ['collection' => trim($m[1]), 'key' => $m[2], 'value' => $m[3]];
        }

        return null;
    }

    /**
     * Increment the step counter and check limits.
     */
    private function incrementStep(): void
    {
        $this->stepCount++;

        if ($this->stepCount > $this->config->stepLimit) {
            throw ViewException::sandboxStepLimitExceeded($this->config->stepLimit);
        }

        // Wall-clock check at configured interval
        if ($this->stepCount % $this->config->wallClockCheckInterval === 0) {
            $elapsed = microtime(true) - $this->startTime;

            if ($elapsed > $this->config->wallClockLimitSeconds) {
                throw ViewException::sandboxWallClockExceeded();
            }
        }
    }

    /**
     * Append to output and check size limit.
     */
    private function appendOutput(string $text): void
    {
        $this->output .= $text;
        $this->outputSize += strlen($text);

        if ($this->outputSize > $this->config->outputSizeLimit) {
            throw ViewException::sandboxOutputSizeLimitExceeded($this->config->outputSizeLimit);
        }
    }
}
