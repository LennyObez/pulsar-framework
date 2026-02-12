<?php

declare(strict_types=1);

namespace Pulsar\Extension\Graphql\Execution;

use Pulsar\Api\Internal;

use function array_key_exists;
use function ctype_alnum;
use function ctype_digit;
use function is_numeric;
use function strlen;
use function substr;

/**
 * Lightweight recursive descent parser for GraphQL query strings.
 *
 * Supports: queries (with optional operation name), field selection sets,
 * arguments with scalar values, aliases, fragment definitions, and fragment spreads.
 * Does not support mutations, subscriptions, directives, or inline fragments.
 */
#[Internal(reason: 'Parser internals — use GraphqlExecutor as public entry point')]
final class GraphqlParser
{
    private string $source;
    private int $pos;
    private int $len;

    /** @var array<string, string|int|float|bool|null> */
    private array $variables;

    /**
     * Parse a GraphQL query string into a ParsedQuery.
     *
     * @param array<string, string|int|float|bool|null> $variables Variable values
     * @throws GraphqlException If the query is malformed
     */
    public function parse(string $query, array $variables = []): ParsedQuery
    {
        $this->source = $query;
        $this->pos = 0;
        $this->len = strlen($query);
        $this->variables = $variables;

        $this->skipWhitespaceAndComments();

        // Parse fragment definitions first (they can appear before or after operations)
        $fragments = [];
        $fields = [];

        while ($this->pos < $this->len) {
            $this->skipWhitespaceAndComments();

            if ($this->pos >= $this->len) {
                break;
            }

            if ($this->peekWord('fragment')) {
                $this->consumeWord('fragment');
                [$name, $selectionFields] = $this->parseFragmentDefinition();
                $fragments[$name] = $selectionFields;
            } else {
                // Skip optional 'query' keyword and operation name
                if ($this->peekWord('query')) {
                    $this->consumeWord('query');
                    $this->skipWhitespaceAndComments();

                    // Skip optional operation name
                    if ($this->pos < $this->len && $this->isNameStart($this->source[$this->pos])) {
                        $this->parseName();
                    }

                    // Skip optional variable definitions
                    $this->skipWhitespaceAndComments();

                    if ($this->pos < $this->len && $this->source[$this->pos] === '(') {
                        $this->skipVariableDefinitions();
                    }
                }

                $fields = $this->parseSelectionSet();
            }
        }

        // Resolve fragment spreads
        $fields = $this->resolveFragments($fields, $fragments);

        return new ParsedQuery($fields, $fragments);
    }

    /**
     * @return list<ParsedField>
     */
    private function parseSelectionSet(): array
    {
        $this->skipWhitespaceAndComments();
        $this->expect('{');
        $this->skipWhitespaceAndComments();

        $fields = [];

        while ($this->pos < $this->len && $this->source[$this->pos] !== '}') {
            $this->skipWhitespaceAndComments();

            if ($this->pos < $this->len && $this->source[$this->pos] === '}') {
                break;
            }

            // Fragment spread
            if ($this->pos + 2 < $this->len && substr($this->source, $this->pos, 3) === '...') {
                $this->pos += 3;
                $this->skipWhitespaceAndComments();
                $fragmentName = $this->parseName();
                // Create a placeholder that will be resolved later
                $fields[] = new ParsedField('__fragment_spread', alias: $fragmentName);
            } else {
                $fields[] = $this->parseField();
            }

            $this->skipWhitespaceAndComments();
        }

        $this->expect('}');

        return $fields;
    }

    private function parseField(): ParsedField
    {
        $nameOrAlias = $this->parseName();
        $this->skipWhitespaceAndComments();

        $alias = null;
        $name = $nameOrAlias;

        // Check for alias: "alias: fieldName"
        if ($this->pos < $this->len && $this->source[$this->pos] === ':') {
            $this->pos++; // consume ':'
            $this->skipWhitespaceAndComments();
            $alias = $nameOrAlias;
            $name = $this->parseName();
            $this->skipWhitespaceAndComments();
        }

        // Parse arguments
        $arguments = [];

        if ($this->pos < $this->len && $this->source[$this->pos] === '(') {
            $arguments = $this->parseArguments();
        }

        $this->skipWhitespaceAndComments();

        // Parse sub-selection set
        $selections = [];

        if ($this->pos < $this->len && $this->source[$this->pos] === '{') {
            $selections = $this->parseSelectionSet();
        }

        return new ParsedField($name, $alias, $arguments, $selections);
    }

    /**
     * @return array<string, string|int|float|bool|null>
     */
    private function parseArguments(): array
    {
        $this->expect('(');
        $this->skipWhitespaceAndComments();

        $args = [];

        while ($this->pos < $this->len && $this->source[$this->pos] !== ')') {
            $this->skipWhitespaceAndComments();
            $argName = $this->parseName();
            $this->skipWhitespaceAndComments();
            $this->expect(':');
            $this->skipWhitespaceAndComments();
            $args[$argName] = $this->parseValue();
            $this->skipWhitespaceAndComments();

            // Optional comma
            if ($this->pos < $this->len && $this->source[$this->pos] === ',') {
                $this->pos++;
                $this->skipWhitespaceAndComments();
            }
        }

        $this->expect(')');

        return $args;
    }

    private function parseValue(): string|int|float|bool|null
    {
        if ($this->pos >= $this->len) {
            throw GraphqlException::syntaxError('Unexpected end of input');
        }

        $char = $this->source[$this->pos];

        // Variable reference: $varName
        if ($char === '$') {
            $this->pos++;
            $varName = $this->parseName();

            if (array_key_exists($varName, $this->variables)) {
                return $this->variables[$varName];
            }

            return null;
        }

        // String literal
        if ($char === '"') {
            return $this->parseStringLiteral();
        }

        // Number (int or float)
        if ($char === '-' || ctype_digit($char)) {
            return $this->parseNumber();
        }

        // Boolean or null keyword
        if ($this->peekWord('true')) {
            $this->consumeWord('true');

            return true;
        }

        if ($this->peekWord('false')) {
            $this->consumeWord('false');

            return false;
        }

        if ($this->peekWord('null')) {
            $this->consumeWord('null');

            return null;
        }

        // Enum value (unquoted identifier)
        if ($this->isNameStart($char)) {
            return $this->parseName();
        }

        throw GraphqlException::syntaxError("Unexpected character '{$char}' at position {$this->pos}");
    }

    private function parseStringLiteral(): string
    {
        $this->expect('"');
        $result = '';

        while ($this->pos < $this->len) {
            $char = $this->source[$this->pos];

            if ($char === '"') {
                $this->pos++;

                return $result;
            }

            if ($char === '\\') {
                $this->pos++;

                if ($this->pos >= $this->len) {
                    throw GraphqlException::syntaxError('Unexpected end of string');
                }

                $escaped = $this->source[$this->pos];
                $result .= match ($escaped) {
                    '"' => '"',
                    '\\' => '\\',
                    '/' => '/',
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    default => $escaped,
                };
            } else {
                $result .= $char;
            }

            $this->pos++;
        }

        throw GraphqlException::syntaxError('Unterminated string literal');
    }

    private function parseNumber(): int|float
    {
        $start = $this->pos;

        if ($this->pos < $this->len && $this->source[$this->pos] === '-') {
            $this->pos++;
        }

        while ($this->pos < $this->len && ctype_digit($this->source[$this->pos])) {
            $this->pos++;
        }

        $isFloat = false;

        if ($this->pos < $this->len && $this->source[$this->pos] === '.') {
            $isFloat = true;
            $this->pos++;

            while ($this->pos < $this->len && ctype_digit($this->source[$this->pos])) {
                $this->pos++;
            }
        }

        $numStr = substr($this->source, $start, $this->pos - $start);

        if (!is_numeric($numStr)) {
            throw GraphqlException::syntaxError("Invalid number: {$numStr}");
        }

        return $isFloat ? (float) $numStr : (int) $numStr;
    }

    private function parseName(): string
    {
        $this->skipWhitespaceAndComments();

        if ($this->pos >= $this->len || !$this->isNameStart($this->source[$this->pos])) {
            throw GraphqlException::syntaxError(
                "Expected name at position {$this->pos}, got: "
                . ($this->pos < $this->len ? "'{$this->source[$this->pos]}'" : 'EOF'),
            );
        }

        $start = $this->pos;

        while ($this->pos < $this->len && $this->isNameContinue($this->source[$this->pos])) {
            $this->pos++;
        }

        return substr($this->source, $start, $this->pos - $start);
    }

    /**
     * @return array{string, list<ParsedField>}
     */
    private function parseFragmentDefinition(): array
    {
        $this->skipWhitespaceAndComments();
        $name = $this->parseName();
        $this->skipWhitespaceAndComments();

        // "on TypeName"
        $this->consumeWord('on');
        $this->skipWhitespaceAndComments();
        $this->parseName(); // type condition — consumed but not used for resolution

        $fields = $this->parseSelectionSet();

        return [$name, $fields];
    }

    private function skipVariableDefinitions(): void
    {
        $depth = 0;

        while ($this->pos < $this->len) {
            $char = $this->source[$this->pos];
            $this->pos++;

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    return;
                }
            }
        }
    }

    /**
     * Resolve fragment spreads in a field list.
     *
     * @param list<ParsedField> $fields
     * @param array<string, list<ParsedField>> $fragments
     * @return list<ParsedField>
     */
    private function resolveFragments(array $fields, array $fragments): array
    {
        $resolved = [];

        foreach ($fields as $field) {
            if ($field->name === '__fragment_spread') {
                $fragmentName = $field->alias;

                if ($fragmentName !== null && isset($fragments[$fragmentName])) {
                    foreach ($this->resolveFragments($fragments[$fragmentName], $fragments) as $f) {
                        $resolved[] = $f;
                    }
                }
            } else {
                $subSelections = $field->selections !== []
                    ? $this->resolveFragments($field->selections, $fragments)
                    : [];

                $resolved[] = new ParsedField(
                    $field->name,
                    $field->alias,
                    $field->arguments,
                    $subSelections,
                );
            }
        }

        return $resolved;
    }

    private function skipWhitespaceAndComments(): void
    {
        while ($this->pos < $this->len) {
            $char = $this->source[$this->pos];

            if ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r" || $char === ',') {
                $this->pos++;
            } elseif ($char === '#') {
                // Line comment — skip until end of line
                while ($this->pos < $this->len && $this->source[$this->pos] !== "\n") {
                    $this->pos++;
                }
            } else {
                break;
            }
        }
    }

    private function expect(string $char): void
    {
        if ($this->pos >= $this->len || $this->source[$this->pos] !== $char) {
            $actual = $this->pos < $this->len ? "'{$this->source[$this->pos]}'" : 'EOF';
            throw GraphqlException::syntaxError("Expected '{$char}' at position {$this->pos}, got {$actual}");
        }

        $this->pos++;
    }

    private function peekWord(string $word): bool
    {
        $wordLen = strlen($word);

        if ($this->pos + $wordLen > $this->len) {
            return false;
        }

        if (substr($this->source, $this->pos, $wordLen) !== $word) {
            return false;
        }

        // Ensure the word is not part of a larger identifier
        if ($this->pos + $wordLen < $this->len && $this->isNameContinue($this->source[$this->pos + $wordLen])) {
            return false;
        }

        return true;
    }

    private function consumeWord(string $word): void
    {
        if (!$this->peekWord($word)) {
            throw GraphqlException::syntaxError("Expected '{$word}' at position {$this->pos}");
        }

        $this->pos += strlen($word);
    }

    private function isNameStart(string $char): bool
    {
        return ctype_alnum($char) || $char === '_';
    }

    private function isNameContinue(string $char): bool
    {
        return ctype_alnum($char) || $char === '_';
    }
}
