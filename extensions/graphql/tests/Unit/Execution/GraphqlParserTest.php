<?php

declare(strict_types=1);

namespace Pulsar\Extension\Graphql\Tests\Unit\Execution;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Graphql\Execution\GraphqlException;
use Pulsar\Extension\Graphql\Execution\GraphqlParser;

final class GraphqlParserTest extends TestCase
{
    private GraphqlParser $parser;

    protected function setUp(): void
    {
        $this->parser = new GraphqlParser();
    }

    #[Test]
    public function parseSimpleQuery(): void
    {
        $query = '{ content(id: "1") { id title } }';

        $parsed = $this->parser->parse($query);

        self::assertCount(1, $parsed->fields);
        self::assertSame('content', $parsed->fields[0]->name);
        self::assertSame('1', $parsed->fields[0]->arguments['id']);
        self::assertCount(2, $parsed->fields[0]->selections);
        self::assertSame('id', $parsed->fields[0]->selections[0]->name);
        self::assertSame('title', $parsed->fields[0]->selections[1]->name);
    }

    #[Test]
    public function parseQueryWithQueryKeyword(): void
    {
        $query = 'query { content(id: "1") { id } }';

        $parsed = $this->parser->parse($query);

        self::assertCount(1, $parsed->fields);
        self::assertSame('content', $parsed->fields[0]->name);
    }

    #[Test]
    public function parseQueryWithOperationName(): void
    {
        $query = 'query GetContent { content(id: "1") { id } }';

        $parsed = $this->parser->parse($query);

        self::assertCount(1, $parsed->fields);
    }

    #[Test]
    public function parseIntegerArgument(): void
    {
        $query = '{ contents(locale: "en", page: 2) { totalCount } }';

        $parsed = $this->parser->parse($query);

        self::assertSame(2, $parsed->fields[0]->arguments['page']);
    }

    #[Test]
    public function parseFloatArgument(): void
    {
        $query = '{ content(weight: 3.14) { id } }';

        $parsed = $this->parser->parse($query);

        self::assertSame(3.14, $parsed->fields[0]->arguments['weight']);
    }

    #[Test]
    public function parseBooleanArguments(): void
    {
        $query = '{ content(active: true, deleted: false) { id } }';

        $parsed = $this->parser->parse($query);

        self::assertTrue($parsed->fields[0]->arguments['active']);
        self::assertFalse($parsed->fields[0]->arguments['deleted']);
    }

    #[Test]
    public function parseNullArgument(): void
    {
        $query = '{ content(parent: null) { id } }';

        $parsed = $this->parser->parse($query);

        self::assertNull($parsed->fields[0]->arguments['parent']);
    }

    #[Test]
    public function parseAlias(): void
    {
        $query = '{ myContent: content(id: "1") { id } }';

        $parsed = $this->parser->parse($query);

        self::assertSame('content', $parsed->fields[0]->name);
        self::assertSame('myContent', $parsed->fields[0]->alias);
        self::assertSame('myContent', $parsed->fields[0]->responseKey());
    }

    #[Test]
    public function parseNestedSelectionSets(): void
    {
        $query = '{ content(id: "1") { translations { locale title } } }';

        $parsed = $this->parser->parse($query);

        $translations = $parsed->fields[0]->selections[0];
        self::assertSame('translations', $translations->name);
        self::assertCount(2, $translations->selections);
    }

    #[Test]
    public function parseVariableSubstitution(): void
    {
        $query = '{ content(id: $contentId) { id } }';

        $parsed = $this->parser->parse($query, ['contentId' => 'var-123']);

        self::assertSame('var-123', $parsed->fields[0]->arguments['id']);
    }

    #[Test]
    public function parseVariableReturnsNullForUndefinedVariable(): void
    {
        $query = '{ content(id: $missing) { id } }';

        $parsed = $this->parser->parse($query);

        self::assertNull($parsed->fields[0]->arguments['id']);
    }

    #[Test]
    public function parseSkipsComments(): void
    {
        $query = <<<'GQL'
            # This is a comment
            {
                content(id: "1") {
                    # Another comment
                    id
                }
            }
            GQL;

        $parsed = $this->parser->parse($query);

        self::assertCount(1, $parsed->fields);
    }

    #[Test]
    public function parseFragmentDefinitionAndSpread(): void
    {
        $query = <<<'GQL'
            fragment ContentFields on Content {
                id
                contentType
            }

            {
                content(id: "1") {
                    ...ContentFields
                }
            }
            GQL;

        $parsed = $this->parser->parse($query);

        self::assertCount(2, $parsed->fields[0]->selections);
        self::assertSame('id', $parsed->fields[0]->selections[0]->name);
        self::assertSame('contentType', $parsed->fields[0]->selections[1]->name);
    }

    #[Test]
    public function parseThrowsOnMissingOpenBrace(): void
    {
        $this->expectException(GraphqlException::class);
        $this->parser->parse('content(id: "1") { id }');
    }

    #[Test]
    public function parseQueryWithVariableDefinitions(): void
    {
        $query = 'query GetContent($id: ID!) { content(id: $id) { id } }';

        $parsed = $this->parser->parse($query, ['id' => 'abc']);

        self::assertSame('abc', $parsed->fields[0]->arguments['id']);
    }

    #[Test]
    public function parseEscapedStringLiteral(): void
    {
        $query = '{ content(title: "hello\nworld") { id } }';

        $parsed = $this->parser->parse($query);

        self::assertSame("hello\nworld", $parsed->fields[0]->arguments['title']);
    }

    #[Test]
    public function parseNegativeNumber(): void
    {
        $query = '{ content(offset: -5) { id } }';

        $parsed = $this->parser->parse($query);

        self::assertSame(-5, $parsed->fields[0]->arguments['offset']);
    }

    #[Test]
    public function parseEnumValue(): void
    {
        $query = '{ content(status: PUBLISHED) { id } }';

        $parsed = $this->parser->parse($query);

        self::assertSame('PUBLISHED', $parsed->fields[0]->arguments['status']);
    }
}
