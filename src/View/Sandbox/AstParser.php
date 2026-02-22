<?php

declare(strict_types=1);

namespace Pulsar\View\Sandbox;

use Pulsar\Api\Internal;
use Pulsar\View\ViewException;

use function array_pop;
use function count;
use function preg_match;
use function preg_match_all;
use function strlen;
use function substr;
use function trim;

use const PREG_OFFSET_CAPTURE;

/**
 * Parses untrusted template source into an AST tree.
 *
 * Only a restricted subset of directives is recognized:
 * if, elseif, else, endif, foreach, endforeach, include, i18n
 *
 * Raw output ({!! !!}) and php are NOT supported. Any attempt to use
 * them results in a parse error.
 */
#[Internal(reason: 'Parser internals are an engine implementation detail')]
final class AstParser
{
    /** @var array<int, array{node: AstNode, children: list<AstNode>, elseChildren: list<AstNode>, inElse: bool}> */
    private array $stack = [];

    /**
     * Parse a template source string into an AST.
     *
     * @throws ViewException If the template contains disallowed constructs
     */
    public function parse(string $source): AstNode
    {
        $this->validateNoDisallowedConstructs($source);

        $tokens = $this->tokenize($source);

        $this->stack = [['node' => new AstNode(AstNodeType::Root), 'children' => [], 'elseChildren' => [], 'inElse' => false]];

        foreach ($tokens as $token) {
            if ($token['type'] === 'text') {
                if ($token['value'] !== '') {
                    $this->appendNode(new AstNode(AstNodeType::Text, value: $token['value']));
                }

                continue;
            }

            if ($token['type'] === 'output') {
                $this->appendNode(new AstNode(AstNodeType::Output, value: trim($token['value'])));

                continue;
            }

            if ($token['type'] === 'directive') {
                $this->handleDirective($token);
            }
        }

        if (count($this->stack) !== 1) {
            throw ViewException::compilationFailed(
                '<sandbox>',
                'unclosed directive block',
            );
        }

        return new AstNode(AstNodeType::Root, children: $this->stack[0]['children']);
    }

    /**
     * Append a node to the current scope (children or elseChildren).
     */
    private function appendNode(AstNode $node): void
    {
        if ($this->stack === []) {
            return;
        }

        $idx = count($this->stack) - 1;

        if ($this->stack[$idx]['inElse']) {
            $this->stack[$idx]['elseChildren'][] = $node;
        } else {
            $this->stack[$idx]['children'][] = $node;
        }
    }

    /**
     * Tokenize template source into raw tokens.
     *
     * Uses offset-based matching to reliably extract all template constructs.
     *
     * @return list<array{type: string, value: string, tag: string}>
     */
    private function tokenize(string $source): array
    {
        // Match: {{ expr }} or @directive(expr) or @directive
        $pattern = '/\{\{\s*(.+?)\s*}}|@(if|elseif|else|endif|foreach|endforeach|include|i18n)\b(?:\s*\(([^)]*)\))?/s';

        $matches = [];
        preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);

        $tokens = [];
        $cursor = 0;

        foreach ($matches[0] as $i => $match) {
            $fullMatch = $match[0];
            $offset = $match[1];

            // Add preceding text
            if ($offset > $cursor) {
                $text = substr($source, $cursor, $offset - $cursor);
                $tokens[] = ['type' => 'text', 'value' => $text, 'tag' => ''];
            }

            // Determine token type
            if ($matches[1][$i][0] !== '') {
                // {{ expr }} — output token
                $tokens[] = ['type' => 'output', 'value' => $matches[1][$i][0], 'tag' => ''];
            } else {
                // @directive — directive token
                $tag = $matches[2][$i][0];
                $expr = ($matches[3][$i][0] !== '') ? $matches[3][$i][0] : '';
                $tokens[] = ['type' => 'directive', 'value' => $expr, 'tag' => $tag];
            }

            $cursor = $offset + strlen($fullMatch);
        }

        // Add remaining text
        if ($cursor < strlen($source)) {
            $tokens[] = ['type' => 'text', 'value' => substr($source, $cursor), 'tag' => ''];
        }

        return $tokens;
    }

    /**
     * Handle a directive token during parsing.
     *
     * @param array{type: string, value: string, tag: string} $token
     */
    private function handleDirective(array $token): void
    {
        if ($this->stack === []) {
            return;
        }

        $tag = $token['tag'];
        $expr = trim($token['value']);

        switch ($tag) {
            case 'if':
                $placeholder = new AstNode(AstNodeType::If, value: $expr);
                $this->stack[] = ['node' => $placeholder, 'children' => [], 'elseChildren' => [], 'inElse' => false];
                break;

            case 'elseif':
                $idx = count($this->stack) - 1;
                $this->stack[$idx]['inElse'] = true;
                $nestedIf = new AstNode(AstNodeType::If, value: $expr);
                $this->stack[] = ['node' => $nestedIf, 'children' => [], 'elseChildren' => [], 'inElse' => false];
                break;

            case 'else':
                $idx = count($this->stack) - 1;
                $this->stack[$idx]['inElse'] = true;
                break;

            case 'endif':
                if (count($this->stack) < 2) {
                    throw ViewException::invalidDirective('endif', 'no matching @if');
                }

                $finished = array_pop($this->stack);
                $node = new AstNode(
                    AstNodeType::If,
                    value: $finished['node']->value,
                    children: $finished['children'],
                    elseChildren: $finished['elseChildren'],
                );

                $this->appendNode($node);

                break;

            case 'foreach':
                $placeholder = new AstNode(AstNodeType::Foreach, value: $expr);
                $this->stack[] = ['node' => $placeholder, 'children' => [], 'elseChildren' => [], 'inElse' => false];
                break;

            case 'endforeach':
                if (count($this->stack) < 2) {
                    throw ViewException::invalidDirective('endforeach', 'no matching @foreach');
                }

                $finished = array_pop($this->stack);
                $node = new AstNode(
                    AstNodeType::Foreach,
                    value: $finished['node']->value,
                    children: $finished['children'],
                );

                $this->appendNode($node);

                break;

            case 'include':
                $this->appendNode(new AstNode(AstNodeType::Include, value: $expr));

                break;

            case 'i18n':
                $this->appendNode(new AstNode(AstNodeType::I18n, value: $expr));

                break;

            default:
                throw ViewException::invalidDirective(
                    $tag,
                    'directive is not allowed in untrusted template mode',
                );
        }
    }

    /**
     * Validate that the template contains no disallowed constructs.
     *
     * @throws ViewException If raw output, php, or other unsafe patterns are found
     */
    private function validateNoDisallowedConstructs(string $source): void
    {
        if (preg_match('/\{!!.*?!!}/s', $source) === 1) {
            throw ViewException::rawOutputInUntrustedMode();
        }

        if (preg_match('/@php\b/', $source) === 1) {
            throw ViewException::invalidDirective('php', 'not allowed in untrusted template mode');
        }

        if (preg_match('/<\?php\b/', $source) === 1) {
            throw ViewException::compilationFailed('<sandbox>', 'PHP tags are not allowed in untrusted templates');
        }

        if (preg_match('/<\?=/', $source) === 1) {
            throw ViewException::compilationFailed('<sandbox>', 'PHP short echo tags are not allowed in untrusted templates');
        }

        // Detect disallowed directives (anything not in the allowed set)
        if (preg_match('/@(extends|section|endsection|yield|component|endcomponent|slot|endslot|auth|endauth|guest|endguest|can|endcan|csrf|method|php|endphp|for|endfor|while|endwhile|switch|case|default|endswitch)\b/', $source, $m) === 1) {
            throw ViewException::invalidDirective(
                $m[1],
                'directive is not allowed in untrusted template mode',
            );
        }
    }
}
