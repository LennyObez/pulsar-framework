<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Sandbox;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Sandbox\AstNode;
use Pulsar\View\Sandbox\AstNodeType;

#[CoversClass(AstNode::class)]
#[CoversClass(AstNodeType::class)]
final class AstNodeTest extends TestCase
{
    #[Test]
    public function constructSetsTypeAndDefaults(): void
    {
        $node = new AstNode(AstNodeType::Text);

        self::assertSame(AstNodeType::Text, $node->type);
        self::assertSame('', $node->value);
        self::assertSame([], $node->children);
        self::assertSame([], $node->elseChildren);
        self::assertSame('', $node->tag);
    }

    #[Test]
    public function constructWithAllParameters(): void
    {
        $child = new AstNode(AstNodeType::Text, 'inner');
        $elseChild = new AstNode(AstNodeType::Text, 'else-branch');

        $node = new AstNode(
            type: AstNodeType::If,
            value: '$user->isAdmin()',
            children: [$child],
            elseChildren: [$elseChild],
            tag: 'if',
        );

        self::assertSame(AstNodeType::If, $node->type);
        self::assertSame('$user->isAdmin()', $node->value);
        self::assertCount(1, $node->children);
        self::assertCount(1, $node->elseChildren);
        self::assertSame('if', $node->tag);
    }

    #[Test]
    public function allNodeTypesExist(): void
    {
        $types = AstNodeType::cases();

        self::assertContains(AstNodeType::Text, $types);
        self::assertContains(AstNodeType::Output, $types);
        self::assertContains(AstNodeType::If, $types);
        self::assertContains(AstNodeType::Foreach, $types);
        self::assertContains(AstNodeType::Include, $types);
        self::assertContains(AstNodeType::I18n, $types);
        self::assertContains(AstNodeType::Root, $types);
    }

    #[Test]
    public function nodeTypeValues(): void
    {
        self::assertSame('text', AstNodeType::Text->value);
        self::assertSame('output', AstNodeType::Output->value);
        self::assertSame('if', AstNodeType::If->value);
        self::assertSame('foreach', AstNodeType::Foreach->value);
        self::assertSame('root', AstNodeType::Root->value);
    }
}
