<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\AI;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\AI\PromptTemplate;
use Pulsar\Extension\Cms\AI\PromptTemplateRegistry;

#[CoversClass(PromptTemplateRegistry::class)]
final class PromptTemplateRegistryTest extends TestCase
{
    #[Test]
    public function registerAndGet(): void
    {
        $registry = new PromptTemplateRegistry();
        $template = new PromptTemplate('test', 'Hello {name}', 'System', 0.7, 1024);

        $registry->register($template);

        self::assertSame($template, $registry->get('test'));
    }

    #[Test]
    public function getReturnsNullForUnknown(): void
    {
        $registry = new PromptTemplateRegistry();

        self::assertNull($registry->get('nonexistent'));
    }

    #[Test]
    public function hasReturnsTrueForRegistered(): void
    {
        $registry = new PromptTemplateRegistry();
        $registry->register(new PromptTemplate('test', 'T', 'S', 0.5, 256));

        self::assertTrue($registry->has('test'));
        self::assertFalse($registry->has('other'));
    }

    #[Test]
    public function allReturnsAllRegisteredTemplates(): void
    {
        $registry = new PromptTemplateRegistry();
        $t1 = new PromptTemplate('a', 'A', 'S', 0.5, 256);
        $t2 = new PromptTemplate('b', 'B', 'S', 0.5, 256);

        $registry->register($t1);
        $registry->register($t2);

        $all = $registry->all();
        self::assertCount(2, $all);
        self::assertSame($t1, $all['a']);
        self::assertSame($t2, $all['b']);
    }

    #[Test]
    public function registerOverwritesPrevious(): void
    {
        $registry = new PromptTemplateRegistry();
        $original = new PromptTemplate('test', 'Original', 'S', 0.5, 256);
        $replacement = new PromptTemplate('test', 'Replacement', 'S', 0.5, 256);

        $registry->register($original);
        $registry->register($replacement);

        self::assertSame($replacement, $registry->get('test'));
        self::assertCount(1, $registry->all());
    }
}
