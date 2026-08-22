<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen\Template;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\Template\TemplateRenderer;
use Pulsar\Codegen\Template\TemplateVariable;

#[CoversClass(TemplateRenderer::class)]
final class TemplateRendererTest extends TestCase
{
    private TemplateRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new TemplateRenderer();
    }

    #[Test]
    public function renderReturnsTemplateUnchangedWhenNoPlaceholders(): void
    {
        $result = $this->renderer->render('Hello, World!', []);

        self::assertSame('Hello, World!', $result);
    }

    #[Test]
    public function renderSubstitutesSimpleVariable(): void
    {
        $result = $this->renderer->render(
            'namespace App\Models\{{name}};',
            [new TemplateVariable('name', 'User')],
        );

        self::assertSame('namespace App\Models\User;', $result);
    }

    #[Test]
    public function renderSubstitutesMultipleVariables(): void
    {
        $result = $this->renderer->render(
            'class {{className}} extends {{baseClass}}',
            [
                new TemplateVariable('className', 'UserRepository'),
                new TemplateVariable('baseClass', 'AbstractRepository'),
            ],
        );

        self::assertSame('class UserRepository extends AbstractRepository', $result);
    }

    #[Test]
    public function renderSubstitutesRepeatedPlaceholders(): void
    {
        $result = $this->renderer->render(
            '{{name}} and {{name}} again',
            [new TemplateVariable('name', 'Foo')],
        );

        self::assertSame('Foo and Foo again', $result);
    }

    #[Test]
    public function renderLeavesEmptyStringForMissingVariable(): void
    {
        $result = $this->renderer->render(
            'Hello {{missing}}!',
            [],
        );

        self::assertSame('Hello !', $result);
    }

    #[Test]
    public function renderAppliesPascalCaseFilter(): void
    {
        $result = $this->renderer->render(
            '{{name|PascalCase}}',
            [new TemplateVariable('name', 'blog_post')],
        );

        self::assertSame('BlogPost', $result);
    }

    #[Test]
    public function renderAppliesCamelCaseFilter(): void
    {
        $result = $this->renderer->render(
            '{{name|camelCase}}',
            [new TemplateVariable('name', 'blog_post')],
        );

        self::assertSame('blogPost', $result);
    }

    #[Test]
    public function renderAppliesSnakeCaseFilter(): void
    {
        $result = $this->renderer->render(
            '{{name|snake_case}}',
            [new TemplateVariable('name', 'BlogPost')],
        );

        self::assertSame('blog_post', $result);
    }

    #[Test]
    public function renderAppliesKebabCaseFilter(): void
    {
        $result = $this->renderer->render(
            '{{name|kebab-case}}',
            [new TemplateVariable('name', 'BlogPost')],
        );

        self::assertSame('blog-post', $result);
    }

    #[Test]
    public function renderThrowsForUnknownFilter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown template filter: UPPERCASE');

        $this->renderer->render(
            '{{name|UPPERCASE}}',
            [new TemplateVariable('name', 'test')],
        );
    }

    #[Test]
    #[DataProvider('caseConversionProvider')]
    public function renderConvertsCasesCorrectly(string $input, string $filter, string $expected): void
    {
        $result = $this->renderer->render(
            "{{name|{$filter}}}",
            [new TemplateVariable('name', $input)],
        );

        self::assertSame($expected, $result);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function caseConversionProvider(): iterable
    {
        yield 'snake to PascalCase' => ['user_profile', 'PascalCase', 'UserProfile'];
        yield 'snake to camelCase' => ['user_profile', 'camelCase', 'userProfile'];
        yield 'camelCase to snake_case' => ['userProfile', 'snake_case', 'user_profile'];
        yield 'camelCase to kebab-case' => ['userProfile', 'kebab-case', 'user-profile'];
        yield 'PascalCase to snake_case' => ['UserProfile', 'snake_case', 'user_profile'];
        yield 'PascalCase to kebab-case' => ['UserProfile', 'kebab-case', 'user-profile'];
        yield 'kebab to PascalCase' => ['user-profile', 'PascalCase', 'UserProfile'];
        yield 'kebab to camelCase' => ['user-profile', 'camelCase', 'userProfile'];
        yield 'single word PascalCase' => ['user', 'PascalCase', 'User'];
        yield 'single word camelCase' => ['User', 'camelCase', 'user'];
        yield 'single word snake_case' => ['User', 'snake_case', 'user'];
        yield 'single word kebab-case' => ['User', 'kebab-case', 'user'];
    }

    #[Test]
    public function renderIsDeterministic(): void
    {
        $template = '{{entity|PascalCase}}Repository extends Base{{entity|PascalCase}}';
        $variables = [new TemplateVariable('entity', 'blog_post')];

        $result1 = $this->renderer->render($template, $variables);
        $result2 = $this->renderer->render($template, $variables);

        self::assertSame($result1, $result2);
        self::assertSame('BlogPostRepository extends BaseBlogPost', $result1);
    }

    #[Test]
    public function renderHandlesMultilineTemplates(): void
    {
        $template = <<<'TPL'
            <?php

            namespace {{namespace}};

            class {{className}} {}
            TPL;

        $result = $this->renderer->render($template, [
            new TemplateVariable('namespace', 'App\\Models'),
            new TemplateVariable('className', 'User'),
        ]);

        self::assertStringContainsString('namespace App\\Models;', $result);
        self::assertStringContainsString('class User {}', $result);
    }

    #[Test]
    public function renderWithMixedFilteredAndUnfilteredPlaceholders(): void
    {
        $result = $this->renderer->render(
            '{{name}} and {{name|snake_case}} and {{name|PascalCase}}',
            [new TemplateVariable('name', 'blogPost')],
        );

        self::assertSame('blogPost and blog_post and BlogPost', $result);
    }
}
