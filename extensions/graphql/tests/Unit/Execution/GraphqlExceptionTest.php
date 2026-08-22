<?php

declare(strict_types=1);

namespace Pulsar\Extension\Graphql\Tests\Unit\Execution;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Graphql\Execution\GraphqlException;

final class GraphqlExceptionTest extends TestCase
{
    #[Test]
    public function syntaxErrorPrefixesMessage(): void
    {
        $e = GraphqlException::syntaxError('Unexpected token');

        self::assertStringContainsString('syntax error', $e->getMessage());
        self::assertStringContainsString('Unexpected token', $e->getMessage());
    }

    #[Test]
    public function validationErrorPrefixesMessage(): void
    {
        $e = GraphqlException::validationError('Missing argument');

        self::assertStringContainsString('validation error', $e->getMessage());
        self::assertStringContainsString('Missing argument', $e->getMessage());
    }

    #[Test]
    public function executionErrorPrefixesMessage(): void
    {
        $e = GraphqlException::executionError('Resolver failed');

        self::assertStringContainsString('execution error', $e->getMessage());
        self::assertStringContainsString('Resolver failed', $e->getMessage());
    }

    #[Test]
    public function queryTooComplexPrefixesMessage(): void
    {
        $e = GraphqlException::queryTooComplex('Maximum query depth exceeded');

        self::assertStringContainsString('query too complex', $e->getMessage());
        self::assertStringContainsString('Maximum query depth exceeded', $e->getMessage());
    }
}
