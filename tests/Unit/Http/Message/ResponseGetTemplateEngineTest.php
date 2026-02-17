<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Message;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

#[CoversClass(Response::class)]
final class ResponseGetTemplateEngineTest extends TestCase
{
    protected function tearDown(): void
    {
        Response::clearTemplateEngine();
    }

    #[Test]
    public function getTemplateEngineReturnsNullWhenNotSet(): void
    {
        Response::clearTemplateEngine();

        self::assertNull(Response::getTemplateEngine());
    }

    #[Test]
    public function getTemplateEngineReturnsSetEngine(): void
    {
        $engine = $this->createStub(TemplateEngineInterface::class);
        Response::setTemplateEngine($engine);

        self::assertSame($engine, Response::getTemplateEngine());
    }

    #[Test]
    public function clearTemplateEngineResetsToNull(): void
    {
        $engine = $this->createStub(TemplateEngineInterface::class);
        Response::setTemplateEngine($engine);
        Response::clearTemplateEngine();

        self::assertNull(Response::getTemplateEngine());
    }
}
