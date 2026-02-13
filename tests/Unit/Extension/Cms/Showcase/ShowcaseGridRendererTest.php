<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Showcase;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Extension\Cms\Showcase\ShowcaseGridRenderer;

#[CoversClass(ShowcaseGridRenderer::class)]
final class ShowcaseGridRendererTest extends TestCase
{
    private ConnectionInterface&Stub $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
    }

    #[Test]
    public function getProjectsReturnsStructuredProjectData(): void
    {
        $projectResult = Result::fromArrays([
            ['id' => 'p1', 'title' => 'My App', 'slug' => 'my-app', 'path' => '/showcase/my-app'],
        ]);

        $fieldResult = Result::fromArrays([
            ['field_key' => 'project_url', 'value_string' => 'https://myapp.com', 'value_json' => null, 'value_bool' => null, 'value_int' => null],
            ['field_key' => 'industry', 'value_string' => 'Technology', 'value_json' => null, 'value_bool' => null, 'value_int' => null],
            ['field_key' => 'is_featured', 'value_string' => null, 'value_json' => null, 'value_bool' => '1', 'value_int' => null],
        ]);

        $this->connection->method('query')->willReturnOnConsecutiveCalls($projectResult, $fieldResult);

        $renderer = new ShowcaseGridRenderer($this->connection);
        $projects = $renderer->getProjects('en');

        self::assertCount(1, $projects);
        self::assertSame('My App', $projects[0]['title']);
        self::assertSame('my-app', $projects[0]['slug']);
        self::assertSame('https://myapp.com', $projects[0]['project_url']);
        self::assertSame('Technology', $projects[0]['industry']);
        self::assertTrue($projects[0]['is_featured']);
    }

    #[Test]
    public function getProjectsReturnsEmptyForNoContent(): void
    {
        $result = Result::fromArrays([]);
        $this->connection->method('query')->willReturn($result);

        $renderer = new ShowcaseGridRenderer($this->connection);
        $projects = $renderer->getProjects('en');

        self::assertSame([], $projects);
    }

    #[Test]
    public function getProjectsFiltersByIndustry(): void
    {
        $projectResult = Result::fromArrays([
            ['id' => 'p1', 'title' => 'Tech App', 'slug' => 'tech-app', 'path' => '/showcase/tech-app'],
            ['id' => 'p2', 'title' => 'Health App', 'slug' => 'health-app', 'path' => '/showcase/health-app'],
        ]);

        $techFields = Result::fromArrays([
            ['field_key' => 'industry', 'value_string' => 'Technology', 'value_json' => null, 'value_bool' => null, 'value_int' => null],
        ]);

        $healthFields = Result::fromArrays([
            ['field_key' => 'industry', 'value_string' => 'Healthcare', 'value_json' => null, 'value_bool' => null, 'value_int' => null],
        ]);

        $this->connection->method('query')->willReturnOnConsecutiveCalls(
            $projectResult,
            $techFields,
            $healthFields,
        );

        $renderer = new ShowcaseGridRenderer($this->connection);
        $projects = $renderer->getProjects('en', industry: 'Technology');

        self::assertCount(1, $projects);
        self::assertSame('Tech App', $projects[0]['title']);
    }

    #[Test]
    public function getProjectsFiltersByFeaturedOnly(): void
    {
        $projectResult = Result::fromArrays([
            ['id' => 'p1', 'title' => 'Featured', 'slug' => 'featured', 'path' => '/showcase/featured'],
            ['id' => 'p2', 'title' => 'Normal', 'slug' => 'normal', 'path' => '/showcase/normal'],
        ]);

        $featuredFields = Result::fromArrays([
            ['field_key' => 'is_featured', 'value_string' => null, 'value_json' => null, 'value_bool' => 'true', 'value_int' => null],
        ]);

        $normalFields = Result::fromArrays([
            ['field_key' => 'is_featured', 'value_string' => null, 'value_json' => null, 'value_bool' => '0', 'value_int' => null],
        ]);

        $this->connection->method('query')->willReturnOnConsecutiveCalls(
            $projectResult,
            $featuredFields,
            $normalFields,
        );

        $renderer = new ShowcaseGridRenderer($this->connection);
        $projects = $renderer->getProjects('en', featuredOnly: true);

        self::assertCount(1, $projects);
        self::assertSame('Featured', $projects[0]['title']);
    }

    #[Test]
    public function getProjectsParsesJsonFieldValues(): void
    {
        $projectResult = Result::fromArrays([
            ['id' => 'p1', 'title' => 'App', 'slug' => 'app', 'path' => '/showcase/app'],
        ]);

        $fieldResult = Result::fromArrays([
            ['field_key' => 'technologies', 'value_string' => null, 'value_json' => '["PHP","TypeScript","React"]', 'value_bool' => null, 'value_int' => null],
        ]);

        $this->connection->method('query')->willReturnOnConsecutiveCalls($projectResult, $fieldResult);

        $renderer = new ShowcaseGridRenderer($this->connection);
        $projects = $renderer->getProjects('en');

        self::assertSame(['PHP', 'TypeScript', 'React'], $projects[0]['technologies']);
    }

    #[Test]
    public function getProjectsDefaultsNullFieldsGracefully(): void
    {
        $projectResult = Result::fromArrays([
            ['id' => 'p1', 'title' => 'Minimal', 'slug' => 'minimal', 'path' => '/showcase/minimal'],
        ]);

        $fieldResult = Result::fromArrays([]);

        $this->connection->method('query')->willReturnOnConsecutiveCalls($projectResult, $fieldResult);

        $renderer = new ShowcaseGridRenderer($this->connection);
        $projects = $renderer->getProjects('en');

        self::assertCount(1, $projects);
        self::assertNull($projects[0]['project_url']);
        self::assertNull($projects[0]['screenshot']);
        self::assertNull($projects[0]['industry']);
        self::assertSame([], $projects[0]['technologies']);
        self::assertNull($projects[0]['company']);
        self::assertFalse($projects[0]['is_featured']);
    }
}
