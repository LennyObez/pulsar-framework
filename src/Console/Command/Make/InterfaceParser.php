<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Make;

use function array_map;
use function count;
use function implode;
use function in_array;
use function is_array;
use function token_get_all;
use function trim;

use const T_COMMENT;
use const T_DOC_COMMENT;
use const T_FUNCTION;
use const T_STRING;
use const T_VARIABLE;
use const T_WHITESPACE;

/**
 * Parses PHP interface files to extract method signatures.
 *
 * Uses token_get_all() for zero-dependency PHP parsing.
 */
final readonly class InterfaceParser
{
    /**
     * Parse an interface source string and return method signatures.
     *
     * @return list<MethodSignature>
     */
    public function parse(string $source): array
    {
        $tokens = token_get_all($source);
        $methods = [];
        $tokenCount = count($tokens);

        for ($i = 0; $i < $tokenCount; $i++) {
            assert($i >= 0);
            $token = $tokens[$i];

            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            // Find method name
            $name = $this->findNextToken($tokens, $i, $tokenCount);
            if ($name === null) {
                continue;
            }

            // Find opening parenthesis
            $parenStart = $this->findNextChar($tokens, $i, $tokenCount);
            if ($parenStart === null) {
                continue;
            }

            // Find matching closing parenthesis
            $parenEnd = $this->findMatchingParen($tokens, $parenStart, $tokenCount);
            if ($parenEnd === null) {
                continue;
            }

            $parameters = $this->parseParameters($tokens, $parenStart + 1, $parenEnd);
            $returnType = $this->parseReturnType($tokens, $parenEnd + 1, $tokenCount);

            $methods[] = new MethodSignature($name, $parameters, $returnType);
        }

        return $methods;
    }

    /**
     * Find the next token of a given type after position $from.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private function findNextToken(array $tokens, int $from, int $count): ?string
    {
        for ($i = $from + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && $token[0] === T_STRING) {
                return $token[1];
            }
            if (is_array($token) && !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                break;
            }
        }

        return null;
    }

    /**
     * Find the next occurrence of a specific character after position $from.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private function findNextChar(array $tokens, int $from, int $count): ?int
    {
        for ($i = $from + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === '(') {
                return $i;
            }
        }

        return null;
    }

    /**
     * Find the matching closing parenthesis.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private function findMatchingParen(array $tokens, int $openPos, int $count): ?int
    {
        $depth = 1;
        for ($i = $openPos + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === '(') {
                $depth++;
            } elseif ($token === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Parse parameters between parentheses.
     *
     * @param list<array{int, string, int}|string> $tokens
     * @return list<array{name: string, type: string, default: string|null}>
     */
    private function parseParameters(array $tokens, int $start, int $end): array
    {
        if ($start >= $end) {
            return [];
        }

        // Split tokens by comma (respecting nesting)
        $paramGroups = [];
        $current = [];
        $depth = 0;

        for ($i = $start; $i < $end; $i++) {
            $token = $tokens[$i];
            if ($token === '(') {
                $depth++;
            } elseif ($token === ')') {
                $depth--;
            }

            if ($token === ',' && $depth === 0) {
                $paramGroups[] = $current;
                $current = [];
            } else {
                $current[] = $token;
            }
        }
        if ($current !== []) {
            $paramGroups[] = $current;
        }

        return array_map(fn(array $group) => $this->parseSingleParameter($group), $paramGroups);
    }

    /**
     * Parse a single parameter token group into name, type, and default.
     *
     * @param list<array{int, string, int}|string> $paramTokens
     * @return array{name: string, type: string, default: string|null}
     */
    private function parseSingleParameter(array $paramTokens): array
    {
        $name = '';
        /** @var list<string> $typeParts */
        $typeParts = [];
        $inDefault = false;
        /** @var list<string> $defaultParts */
        $defaultParts = [];

        foreach ($paramTokens as $token) {
            if (is_array($token)) {
                if ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                if ($token[0] === T_VARIABLE) {
                    $name = $token[1];
                    continue;
                }
                if ($inDefault) {
                    $defaultParts[] = $token[1];
                    continue;
                }
                $typeParts[] = $token[1];
            } else {
                if ($token === '=') {
                    $inDefault = true;
                    continue;
                }
                if ($inDefault) {
                    $defaultParts[] = $token;
                    continue;
                }
                // Type-related tokens like ? | &
                if ($name === '' && in_array($token, ['?', '|', '&', '(', ')'], true)) {
                    $typeParts[] = $token;
                }
            }
        }

        $type = implode('', $typeParts);

        /** @var array{name: string, type: string, default: string|null} */
        return [
            'name' => $name,
            'type' => $type,
            'default' => $defaultParts !== [] ? trim(implode('', $defaultParts)) : null,
        ];
    }

    /**
     * Parse the return type after the closing parenthesis.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private function parseReturnType(array $tokens, int $from, int $count): string
    {
        $foundColon = false;
        $typeParts = [];

        for ($i = $from; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === ';' || $token === '{') {
                break;
            }

            if ($token === ':') {
                $foundColon = true;
                continue;
            }

            if (!$foundColon) {
                continue;
            }

            if (is_array($token)) {
                if ($token[0] === T_WHITESPACE) {
                    continue;
                }
                $typeParts[] = $token[1];
            } else {
                $typeParts[] = $token;
            }
        }

        return implode('', $typeParts);
    }
}
