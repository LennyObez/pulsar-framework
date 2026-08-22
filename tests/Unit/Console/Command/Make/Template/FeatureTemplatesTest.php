<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Make\Template;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Make\Template\FeatureTemplates;

#[CoversClass(FeatureTemplates::class)]
final class FeatureTemplatesTest extends TestCase
{
    private FeatureTemplates $templates;

    protected function setUp(): void
    {
        $this->templates = new FeatureTemplates();
    }

    #[Test]
    public function handlerInterfaceContainsCorrectStructure(): void
    {
        $output = $this->templates->handlerInterface('Search', 'App\\Catalog');

        self::assertStringContainsString('declare(strict_types=1);', $output);
        self::assertStringContainsString('namespace App\\Catalog\\Features\\Search\\Contracts;', $output);
        self::assertStringContainsString('interface SearchHandlerInterface', $output);
        self::assertStringContainsString('#[Api(since: \'1.0.0\')]', $output);
        self::assertStringContainsString('public function handle(ServerRequestInterface $request): Response;', $output);
    }

    #[Test]
    public function handlerContainsImplementation(): void
    {
        $output = $this->templates->handler('Search', 'App\\Catalog');

        self::assertStringContainsString('namespace App\\Catalog\\Features\\Search;', $output);
        self::assertStringContainsString('final readonly class SearchHandler implements SearchHandlerInterface', $output);
        self::assertStringContainsString('#[Override]', $output);
        self::assertStringContainsString("'feature' => 'Search'", $output);
        self::assertStringContainsString('Response::json(', $output);
    }

    #[Test]
    public function handlerTestContainsTestStructure(): void
    {
        $output = $this->templates->handlerTest('Search', 'Catalog', 'App\\Catalog');

        self::assertStringContainsString('namespace Pulsar\\Tests\\Unit\\Modules\\Catalog\\Features\\Search;', $output);
        self::assertStringContainsString('#[CoversClass(SearchHandler::class)]', $output);
        self::assertStringContainsString('final class SearchHandlerTest extends TestCase', $output);
        self::assertStringContainsString('it_implements_the_handler_interface', $output);
        self::assertStringContainsString('it_returns_json_response', $output);
    }

    #[Test]
    public function routeEntryUsesCorrectMethodAndPath(): void
    {
        $output = $this->templates->routeEntry('Search', 'POST');

        self::assertStringContainsString('$router->post(\'/search\'', $output);
        self::assertStringContainsString('[SearchHandler::class, \'handle\']', $output);
        self::assertStringContainsString("'search.handle'", $output);
        self::assertStringContainsString('// Search feature', $output);
    }

    #[Test]
    public function routeEntryWithGetMethod(): void
    {
        $output = $this->templates->routeEntry('Dashboard', 'GET');

        self::assertStringContainsString('$router->get(\'/dashboard\'', $output);
        self::assertStringContainsString('[DashboardHandler::class, \'handle\']', $output);
    }
}
