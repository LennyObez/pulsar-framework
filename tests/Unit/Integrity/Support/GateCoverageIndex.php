<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Support;

use PhpToken;

use function array_keys;
use function basename;
use function count;
use function file_get_contents;
use function glob;
use function in_array;
use function is_dir;
use function sort;
use function str_contains;
use function str_replace;
use function strrpos;
use function substr;

/**
 * Which integrity ratchets exist, and which of their rules have been watched refusing.
 *
 * Both halves are DERIVED. A typed list of gates decays the day someone adds a gate — it
 * is the same shape as the three checkers that each carried their own copy of the
 * composition-root list, and it fails the same way: silently, in the direction of saying
 * everything is covered.
 *
 * ## What counts as a ratchet
 *
 * A `*Test.php` sitting directly in tests/Unit/Integrity that reads the repository root or
 * drives git against this checkout. That is not a naming convention, it is the
 * distinguishing property: a test whose subject is the repository rather than a unit is a
 * gate on the merge, and its silence is a claim about the whole tree. Ordinary unit tests
 * in the same directory — the ones covering the runtime integrity-manifest feature — read
 * no root and are correctly left out.
 *
 * ## What counts as coverage
 *
 * A real `#[GuardsGate(gate: 'Class::method', …)]` attribute somewhere under
 * tests/Unit/Integrity, on the negative test class or on the negative test method itself.
 *
 * "Real" is doing work in that sentence, and the first version of this class got it
 * wrong. It matched the attribute as text, so the sentence you are reading — which names
 * the attribute in prose — registered as a declaration, and so did the failure message of
 * the test that consumes this index. Two gates appeared covered because two comments
 * mentioned them. That is precisely the defect QaCiParityTest was written to refuse one
 * level up ("a comment mentioning a command must not count as running it"), reproduced
 * here within a day of it being written down. So the scan reads tokens: only a
 * `T_ATTRIBUTE` counts, and a docblock or a string literal never does.
 */
final readonly class GateCoverageIndex
{
    /** Where the ratchets live, relative to the repository root. */
    private const string RATCHETS = 'tests/Unit/Integrity';

    public function __construct(private string $root) {}

    /**
     * Every rule a ratchet enforces, as `ShortClassName::methodName`.
     *
     * @return list<string>
     */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->ratchetFiles() as $path) {
            $class = basename($path, '.php');

            foreach (self::testMethods((string) file_get_contents($path)) as $method => $_guarded) {
                $rules[] = $class . '::' . $method;
            }
        }

        sort($rules);

        return $rules;
    }

    /**
     * Every rule some test declares itself the negative test for.
     *
     * @return list<string>
     */
    public function declared(): array
    {
        $declared = [];

        foreach ($this->allIntegrityFiles() as $path) {
            foreach (self::guardedGates((string) file_get_contents($path)) as $gate) {
                $declared[$gate] = true;
            }
        }

        $names = array_keys($declared);
        sort($names);

        /** @var list<string> $names */
        return $names;
    }

    /**
     * Rules that are themselves negative tests, because the method carries the attribute.
     *
     * A `fixture_detects_*` method inside the ratchet it guards is the precedent, and a
     * planted-input assertion like `assertFalse(CompositionRoots::contains(...))` is the
     * same thing written inline. Requiring a second test for those would be asking for a
     * negative test of a negative test.
     *
     * @return list<string>
     */
    public function selfCovering(): array
    {
        $covering = [];

        foreach ($this->ratchetFiles() as $path) {
            $class = basename($path, '.php');

            foreach (self::testMethods((string) file_get_contents($path)) as $method => $guarded) {
                if ($guarded) {
                    $covering[] = $class . '::' . $method;
                }
            }
        }

        sort($covering);

        return $covering;
    }

    /**
     * The ratchets themselves: integrity tests whose subject is the repository.
     *
     * @return list<string>
     */
    public function ratchetFiles(): array
    {
        $files = [];

        foreach (glob($this->root . '/' . self::RATCHETS . '/*Test.php') ?: [] as $path) {
            if (!self::readsTheRepository((string) file_get_contents($path))) {
                continue;
            }

            $files[] = str_replace('\\', '/', $path);
        }

        sort($files);

        return $files;
    }

    /**
     * A test that reads the repository root, or asks git about this checkout, is a gate on
     * the merge rather than a test of a unit.
     */
    public static function readsTheRepository(string $source): bool
    {
        return str_contains($source, 'dirname(__DIR__, 3)')
            || str_contains($source, 'git -C ');
    }

    /**
     * Test methods in a source, mapped to whether they carry #[GuardsGate] themselves.
     *
     * @return array<string, bool>
     */
    public static function testMethods(string $source): array
    {
        $tokens = PhpToken::tokenize($source);
        $count = count($tokens);
        $methods = [];
        $pending = [];

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]->id === T_ATTRIBUTE) {
                [$names, , $i] = self::readAttributeGroup($tokens, $i, $count);
                $pending = [...$pending, ...$names];

                continue;
            }

            // A class declaration consumes the attributes written above it; anything
            // pending after that belongs to the class, not to the next method.
            if (in_array($tokens[$i]->id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $pending = [];

                continue;
            }

            if ($tokens[$i]->id !== T_FUNCTION) {
                continue;
            }

            $name = self::nextName($tokens, $i, $count);

            if ($name !== null && in_array('Test', $pending, true)) {
                $methods[$name] = in_array('GuardsGate', $pending, true);
            }

            $pending = [];
        }

        return $methods;
    }

    /**
     * Gate names declared by a real #[GuardsGate] attribute in a source.
     *
     * @return list<string>
     */
    public static function guardedGates(string $source): array
    {
        $tokens = PhpToken::tokenize($source);
        $count = count($tokens);
        $gates = [];

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]->id !== T_ATTRIBUTE) {
                continue;
            }

            [, $found, $i] = self::readAttributeGroup($tokens, $i, $count);
            $gates = [...$gates, ...$found];
        }

        return $gates;
    }

    /**
     * Reads one `#[...]` group: the attribute names in it, and any GuardsGate `gate:`
     * values, returning the index of its closing bracket.
     *
     * The distinction between a name and an argument is tracked rather than guessed. A
     * first attempt collected every identifier in the group, which meant
     * `#[CoversClass(GuardsGate::class)]` would have counted as a GuardsGate declaration —
     * coverage claimed by a test that merely mentions the attribute in a different
     * position. It is the same mistake as reading a comment as a declaration, one level
     * finer, and it is worth being precise about because the direction of the error is
     * always "looks covered".
     *
     * So: an attribute name is an identifier at parenthesis depth zero, either at the head
     * of the group or after a comma separating attributes. Everything else is an argument.
     *
     * @param array<PhpToken> $tokens
     *
     * @return array{0: list<string>, 1: list<string>, 2: int}
     */
    private static function readAttributeGroup(array $tokens, int $start, int $count): array
    {
        $depth = 1;
        $parentheses = 0;
        $expectingName = true;
        $names = [];
        $gates = [];
        $isGuardsGate = false;
        $expectingGateValue = false;
        $i = $start;

        for ($i = $start + 1; $i < $count && $depth > 0; $i++) {
            $token = $tokens[$i];
            $text = $token->text;

            if ($token->id === T_ATTRIBUTE || $text === '[') {
                $depth++;

                continue;
            }

            if ($text === ']') {
                $depth--;

                continue;
            }

            if ($text === '(') {
                $parentheses++;

                continue;
            }

            if ($text === ')') {
                $parentheses--;

                if ($parentheses === 0) {
                    $isGuardsGate = false;
                    $expectingGateValue = false;
                }

                continue;
            }

            // A comma outside every argument list separates one attribute from the next.
            if ($text === ',' && $parentheses === 0) {
                $expectingName = true;

                continue;
            }

            if (!in_array($token->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                if ($expectingGateValue && $token->id === T_CONSTANT_ENCAPSED_STRING) {
                    $gates[] = substr($text, 1, -1);
                    $expectingGateValue = false;
                }

                continue;
            }

            if ($expectingName && $parentheses === 0) {
                $short = self::shortName($text);
                $names[] = $short;
                $isGuardsGate = $short === 'GuardsGate';
                $expectingName = false;

                continue;
            }

            if ($isGuardsGate && $parentheses >= 1 && $text === 'gate') {
                $expectingGateValue = true;
            }
        }

        return [$names, $gates, $i - 1];
    }

    /**
     * @param array<PhpToken> $tokens
     */
    private static function nextName(array $tokens, int $from, int $count): ?string
    {
        for ($i = $from + 1; $i < $count; $i++) {
            if ($tokens[$i]->isIgnorable()) {
                continue;
            }

            return $tokens[$i]->id === T_STRING ? $tokens[$i]->text : null;
        }

        return null;
    }

    private static function shortName(string $name): string
    {
        $position = strrpos($name, '\\');

        return $position === false ? $name : substr($name, $position + 1);
    }

    /**
     * @return list<string>
     */
    private function allIntegrityFiles(): array
    {
        $files = [];

        foreach (['', '/Negative', '/Support'] as $suffix) {
            $directory = $this->root . '/' . self::RATCHETS . $suffix;

            if (!is_dir($directory)) {
                continue;
            }

            foreach (glob($directory . '/*.php') ?: [] as $path) {
                $files[] = str_replace('\\', '/', $path);
            }
        }

        return $files;
    }
}
