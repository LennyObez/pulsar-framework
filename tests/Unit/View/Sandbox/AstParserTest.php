<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Sandbox;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Sandbox\AstNodeType;
use Pulsar\View\Sandbox\AstParser;
use Pulsar\View\ViewException;

#[CoversClass(AstParser::class)]
final class AstParserTest extends TestCase
{
    private AstParser $parser;

    protected function setUp(): void
    {
        $this->parser = new AstParser();
    }

    #[Test]
    public function parsesPlainTextTemplate(): void
    {
        $ast = $this->parser->parse('Hello, world!');

        self::assertSame(AstNodeType::Root, $ast->type);
        self::assertCount(1, $ast->children);
        self::assertSame(AstNodeType::Text, $ast->children[0]->type);
        self::assertSame('Hello, world!', $ast->children[0]->value);
    }

    #[Test]
    public function parsesOutputExpression(): void
    {
        $ast = $this->parser->parse('{{ $name }}');

        self::assertCount(1, $ast->children);
        self::assertSame(AstNodeType::Output, $ast->children[0]->type);
        self::assertSame('$name', $ast->children[0]->value);
    }

    #[Test]
    public function parsesMixedTextAndOutput(): void
    {
        $ast = $this->parser->parse('Hello {{ $name }}, welcome!');

        self::assertCount(3, $ast->children);
        self::assertSame(AstNodeType::Text, $ast->children[0]->type);
        self::assertSame(AstNodeType::Output, $ast->children[1]->type);
        self::assertSame(AstNodeType::Text, $ast->children[2]->type);
    }

    #[Test]
    public function parsesIfDirective(): void
    {
        $source = '@if($show)Visible@endif';
        $ast = $this->parser->parse($source);

        self::assertCount(1, $ast->children);
        $ifNode = $ast->children[0];
        self::assertSame(AstNodeType::If, $ifNode->type);
        self::assertSame('$show', $ifNode->value);
        self::assertCount(1, $ifNode->children);
    }

    #[Test]
    public function parsesIfElseDirective(): void
    {
        $source = '@if($show)Yes@else No@endif';
        $ast = $this->parser->parse($source);

        self::assertCount(1, $ast->children);
        $ifNode = $ast->children[0];
        self::assertSame(AstNodeType::If, $ifNode->type);
        self::assertNotEmpty($ifNode->children);
        // The else children contain the text " No"
        self::assertNotEmpty($ifNode->elseChildren);
    }

    #[Test]
    public function parsesElseIfDirective(): void
    {
        $source = '@if($a)A@elseif($b)B@endif@endif';
        $ast = $this->parser->parse($source);

        self::assertCount(1, $ast->children);
        $ifNode = $ast->children[0];
        self::assertSame(AstNodeType::If, $ifNode->type);
    }

    #[Test]
    public function parsesForeachDirective(): void
    {
        $source = '@foreach($items as $item){{ $item }}@endforeach';
        $ast = $this->parser->parse($source);

        self::assertCount(1, $ast->children);
        $forNode = $ast->children[0];
        self::assertSame(AstNodeType::Foreach, $forNode->type);
        self::assertSame('$items as $item', $forNode->value);
        self::assertCount(1, $forNode->children);
    }

    #[Test]
    public function parsesIncludeDirective(): void
    {
        $source = '@include(partial)';
        $ast = $this->parser->parse($source);

        self::assertCount(1, $ast->children);
        self::assertSame(AstNodeType::Include, $ast->children[0]->type);
        self::assertSame('partial', $ast->children[0]->value);
    }

    #[Test]
    public function parsesI18nDirective(): void
    {
        $source = '@i18n(greeting.hello)';
        $ast = $this->parser->parse($source);

        self::assertCount(1, $ast->children);
        self::assertSame(AstNodeType::I18n, $ast->children[0]->type);
        self::assertSame('greeting.hello', $ast->children[0]->value);
    }

    #[Test]
    public function rejectsRawOutput(): void
    {
        $this->expectException(ViewException::class);
        $this->parser->parse('{!! $raw !!}');
    }

    #[Test]
    public function rejectsPhpDirective(): void
    {
        $this->expectException(ViewException::class);
        $this->parser->parse('@php echo "test"; @endphp');
    }

    #[Test]
    public function rejectsPhpTags(): void
    {
        $this->expectException(ViewException::class);
        $this->parser->parse('<?php echo "test"; ?>');
    }

    #[Test]
    public function rejectsShortEchoTags(): void
    {
        $this->expectException(ViewException::class);
        $this->parser->parse('<?= $value ?>');
    }

    #[Test]
    public function rejectsDisallowedDirectives(): void
    {
        $this->expectException(ViewException::class);
        $this->parser->parse('@extends(layout)');
    }

    #[Test]
    public function rejectsUnclosedIfBlock(): void
    {
        $this->expectException(ViewException::class);
        $this->parser->parse('@if($show)content');
    }

    #[Test]
    public function rejectsEndifWithoutIf(): void
    {
        $this->expectException(ViewException::class);
        $this->parser->parse('@endif');
    }

    #[Test]
    public function rejectsEndforeachWithoutForeach(): void
    {
        $this->expectException(ViewException::class);
        $this->parser->parse('@endforeach');
    }

    #[Test]
    public function parsesEmptyTemplate(): void
    {
        $ast = $this->parser->parse('');

        self::assertSame(AstNodeType::Root, $ast->type);
        self::assertSame([], $ast->children);
    }

    #[Test]
    public function parsesNestedDirectives(): void
    {
        $source = '@if($a)@foreach($items as $item){{ $item }}@endforeach@endif';
        $ast = $this->parser->parse($source);

        self::assertCount(1, $ast->children);
        $ifNode = $ast->children[0];
        self::assertSame(AstNodeType::If, $ifNode->type);
        self::assertCount(1, $ifNode->children);
        self::assertSame(AstNodeType::Foreach, $ifNode->children[0]->type);
    }

    #[Test]
    public function rejectsSectionDirective(): void
    {
        $this->expectException(ViewException::class);
        $this->parser->parse('@section(content)');
    }

    #[Test]
    public function rejectsComponentDirective(): void
    {
        $this->expectException(ViewException::class);
        $this->parser->parse('@component(alert)');
    }
}
