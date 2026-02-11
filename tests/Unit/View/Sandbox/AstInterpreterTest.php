<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Sandbox;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Sandbox\AstInterpreter;
use Pulsar\View\Sandbox\AstNode;
use Pulsar\View\Sandbox\AstNodeType;
use Pulsar\View\Sandbox\AstParser;
use Pulsar\View\Sandbox\SandboxConfig;
use Pulsar\View\Sandbox\TranslationCallback;
use Pulsar\View\ViewException;

#[CoversClass(AstInterpreter::class)]
final class AstInterpreterTest extends TestCase
{
    private AstParser $parser;

    protected function setUp(): void
    {
        $this->parser = new AstParser();
    }

    #[Test]
    public function rendersPlainText(): void
    {
        $interpreter = new AstInterpreter(new SandboxConfig());
        $ast = $this->parser->parse('Hello, world!');

        $result = $interpreter->interpret($ast, []);

        self::assertSame('Hello, world!', $result);
    }

    #[Test]
    public function rendersVariableOutput(): void
    {
        $interpreter = new AstInterpreter(new SandboxConfig());
        $ast = $this->parser->parse('Hello {{ $name }}!');

        $result = $interpreter->interpret($ast, ['name' => 'Alice']);

        self::assertSame('Hello Alice!', $result);
    }

    #[Test]
    public function escapesHtmlInOutput(): void
    {
        $interpreter = new AstInterpreter(new SandboxConfig());
        $ast = $this->parser->parse('{{ $html }}');

        $result = $interpreter->interpret($ast, ['html' => '<script>alert("xss")</script>']);

        self::assertStringNotContainsString('<script>', $result);
        self::assertStringContainsString('&lt;script&gt;', $result);
    }

    #[Test]
    public function rendersIfTrueBlock(): void
    {
        $interpreter = new AstInterpreter(new SandboxConfig());
        $ast = $this->parser->parse('@if($show)Visible@endif');

        $result = $interpreter->interpret($ast, ['show' => true]);

        self::assertSame('Visible', $result);
    }

    #[Test]
    public function skipsIfFalseBlock(): void
    {
        $interpreter = new AstInterpreter(new SandboxConfig());
        $ast = $this->parser->parse('@if($show)Visible@endif');

        $result = $interpreter->interpret($ast, ['show' => false]);

        self::assertSame('', $result);
    }

    #[Test]
    public function rendersElseBlock(): void
    {
        $interpreter = new AstInterpreter(new SandboxConfig());
        $ast = $this->parser->parse('@if($show)Yes@else No@endif');

        $result = $interpreter->interpret($ast, ['show' => false]);

        self::assertSame(' No', $result);
    }

    #[Test]
    public function rendersForeach(): void
    {
        $interpreter = new AstInterpreter(new SandboxConfig());
        $ast = $this->parser->parse('@foreach($items as $item){{ $item }} @endforeach');

        $result = $interpreter->interpret($ast, ['items' => ['a', 'b', 'c']]);

        self::assertSame('a b c ', $result);
    }

    #[Test]
    public function rendersForeachWithKeyValue(): void
    {
        $interpreter = new AstInterpreter(new SandboxConfig());
        $ast = $this->parser->parse('@foreach($data as $k => $v){{ $k }}={{ $v }} @endforeach');

        $result = $interpreter->interpret($ast, ['data' => ['x' => 1, 'y' => 2]]);

        self::assertSame('x=1 y=2 ', $result);
    }

    #[Test]
    public function resolvesDotNotation(): void
    {
        $interpreter = new AstInterpreter(new SandboxConfig());
        $ast = $this->parser->parse('{{ $user.name }}');

        $result = $interpreter->interpret($ast, ['user' => ['name' => 'Bob']]);

        self::assertSame('Bob', $result);
    }

    #[Test]
    public function outputsIntegerValues(): void
    {
        $interpreter = new AstInterpreter(new SandboxConfig());
        $ast = $this->parser->parse('{{ $count }}');

        $result = $interpreter->interpret($ast, ['count' => 42]);

        self::assertSame('42', $result);
    }

    #[Test]
    public function outputsFloatValues(): void
    {
        $interpreter = new AstInterpreter(new SandboxConfig());
        $ast = $this->parser->parse('{{ $price }}');

        $result = $interpreter->interpret($ast, ['price' => 3.14]);

        self::assertSame('3.14', $result);
    }

    #[Test]
    public function outputsBooleanTrue(): void
    {
        $interpreter = new AstInterpreter(new SandboxConfig());
        $ast = $this->parser->parse('{{ $flag }}');

        $result = $interpreter->interpret($ast, ['flag' => true]);

        self::assertSame('1', $result);
    }

    #[Test]
    public function outputsBooleanFalse(): void
    {
        $interpreter = new AstInterpreter(new SandboxConfig());
        $ast = $this->parser->parse('{{ $flag }}');

        $result = $interpreter->interpret($ast, ['flag' => false]);

        self::assertSame('', $result);
    }

    #[Test]
    public function outputsNullAsEmpty(): void
    {
        $interpreter = new AstInterpreter(new SandboxConfig());
        $ast = $this->parser->parse('{{ $missing }}');

        $result = $interpreter->interpret($ast, []);

        self::assertSame('', $result);
    }

    #[Test]
    public function evaluatesComparison(): void
    {
        $interpreter = new AstInterpreter(new SandboxConfig());
        $ast = $this->parser->parse('@if($a == $b)equal@endif');

        self::assertSame('equal', $interpreter->interpret($ast, ['a' => 1, 'b' => 1]));
        self::assertSame('', $interpreter->interpret($ast, ['a' => 1, 'b' => 2]));
    }

    #[Test]
    public function evaluatesNegation(): void
    {
        $interpreter = new AstInterpreter(new SandboxConfig());
        $ast = $this->parser->parse('@if(!$hidden)shown@endif');

        self::assertSame('shown', $interpreter->interpret($ast, ['hidden' => false]));
        self::assertSame('', $interpreter->interpret($ast, ['hidden' => true]));
    }

    #[Test]
    public function enforcesStepLimit(): void
    {
        $config = new SandboxConfig(stepLimit: 5);
        $interpreter = new AstInterpreter($config);
        // Each node is a step; a long template with many nodes will exceed 5
        $ast = $this->parser->parse('{{ $a }}{{ $b }}{{ $c }}{{ $d }}{{ $e }}{{ $f }}');

        $this->expectException(ViewException::class);
        $interpreter->interpret($ast, ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5, 'f' => 6]);
    }

    #[Test]
    public function enforcesOutputSizeLimit(): void
    {
        $config = new SandboxConfig(outputSizeLimit: 10);
        $interpreter = new AstInterpreter($config);
        $ast = $this->parser->parse('{{ $long }}');

        $this->expectException(ViewException::class);
        $interpreter->interpret($ast, ['long' => str_repeat('x', 20)]);
    }

    #[Test]
    public function enforcesLoopLimit(): void
    {
        $config = new SandboxConfig(loopLimit: 3);
        $interpreter = new AstInterpreter($config);
        $ast = $this->parser->parse('@foreach($items as $item){{ $item }}@endforeach');

        $this->expectException(ViewException::class);
        $interpreter->interpret($ast, ['items' => range(1, 10)]);
    }

    #[Test]
    public function i18nUsesTranslationCallback(): void
    {
        $callback = new TranslationCallback(static fn(string $key, array $data): string => "Translated: $key");
        $interpreter = new AstInterpreter(new SandboxConfig(), $callback);
        $ast = $this->parser->parse('@i18n(greeting)');

        $result = $interpreter->interpret($ast, []);

        self::assertSame('Translated: greeting', $result);
    }

    #[Test]
    public function i18nWithoutCallbackOutputsKey(): void
    {
        $interpreter = new AstInterpreter(new SandboxConfig());
        $ast = $this->parser->parse('@i18n(greeting)');

        $result = $interpreter->interpret($ast, []);

        self::assertSame('greeting', $result);
    }

    #[Test]
    public function foreachWithNonIterableSkipsBody(): void
    {
        $interpreter = new AstInterpreter(new SandboxConfig());
        $ast = $this->parser->parse('@foreach($items as $item)x@endforeach');

        $result = $interpreter->interpret($ast, ['items' => 'not-iterable']);

        self::assertSame('', $result);
    }

    #[Test]
    public function invalidForeachExpressionThrows(): void
    {
        $interpreter = new AstInterpreter(new SandboxConfig());
        // Manually build an AST with invalid foreach expression
        $foreachNode = new AstNode(AstNodeType::Foreach, value: 'invalid_expr');
        $root = new AstNode(AstNodeType::Root, children: [$foreachNode]);

        $this->expectException(ViewException::class);
        $interpreter->interpret($root, []);
    }

    #[Test]
    public function resolvesStringLiterals(): void
    {
        $interpreter = new AstInterpreter(new SandboxConfig());
        $ast = $this->parser->parse("@if('hello' == 'hello')match@endif");

        $result = $interpreter->interpret($ast, []);

        self::assertSame('match', $result);
    }

    #[Test]
    public function resolvesNumericLiterals(): void
    {
        $interpreter = new AstInterpreter(new SandboxConfig());
        $ast = $this->parser->parse('@if($x == 42)found@endif');

        $result = $interpreter->interpret($ast, ['x' => 42]);

        self::assertSame('found', $result);
    }

    #[Test]
    public function resolvesBooleanLiterals(): void
    {
        $interpreter = new AstInterpreter(new SandboxConfig());
        $ast = $this->parser->parse('@if($x == true)yes@endif');

        $result = $interpreter->interpret($ast, ['x' => true]);

        self::assertSame('yes', $result);
    }

    #[Test]
    public function includeWithAllowedTemplate(): void
    {
        $config = new SandboxConfig(
            includeAllowlist: ['partial' => 'included-content'],
        );
        $interpreter = new AstInterpreter($config);
        $ast = $this->parser->parse('@include(partial)');

        $result = $interpreter->interpret($ast, []);

        self::assertSame('included-content', $result);
    }

    #[Test]
    public function includeDisallowedThrows(): void
    {
        $config = new SandboxConfig(includeAllowlist: []);
        $interpreter = new AstInterpreter($config);
        $ast = $this->parser->parse('@include(hacked)');

        $this->expectException(ViewException::class);
        $interpreter->interpret($ast, []);
    }
}
