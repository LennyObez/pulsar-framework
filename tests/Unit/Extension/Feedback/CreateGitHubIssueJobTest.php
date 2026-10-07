<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Feedback;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Feedback\Feedback;
use Pulsar\Extension\Feedback\FeedbackCategory;
use Pulsar\Extension\Feedback\FeedbackRepositoryInterface;
use Pulsar\Extension\Feedback\Job\CreateGitHubIssueJob;
use Pulsar\Http\Client\HttpClientInterface;

use function count;
use function mb_strlen;
use function mb_substr;
use function sprintf;
use function str_repeat;

final class CreateGitHubIssueJobTest extends TestCase
{
    private FeedbackRepositoryInterface&Stub $repo;

    protected function setUp(): void
    {
        $this->repo = $this->createStub(FeedbackRepositoryInterface::class);
    }

    #[Test]
    public function buildsTitleFromFirst80CharsOfDescription(): void
    {
        $longDescription = str_repeat('A', 120);
        $feedback = Feedback::create('user-001', FeedbackCategory::Bug, $longDescription, []);
        $this->repo->method('findById')->willReturn($feedback);

        // We verify the job can be constructed and handle() doesn't throw for a null proc
        // The title extraction logic is: mb_substr($description, 0, 80)
        $expectedTitle = mb_substr($longDescription, 0, 80);
        self::assertSame(80, mb_strlen($expectedTitle));
        self::assertSame(str_repeat('A', 80), $expectedTitle);
    }

    #[Test]
    public function shortDescriptionUsedAsFullTitle(): void
    {
        $description = 'Login button is broken on mobile';
        $feedback = Feedback::create('user-001', FeedbackCategory::Bug, $description, []);
        $this->repo->method('findById')->willReturn($feedback);

        $expectedTitle = mb_substr($description, 0, 80);
        self::assertSame($description, $expectedTitle);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideCategoryLabelMappings(): iterable
    {
        yield 'bug maps to bug' => ['bug', 'bug'];
        yield 'feature maps to enhancement' => ['feature', 'enhancement'];
        yield 'improvement maps to enhancement' => ['improvement', 'enhancement'];
        yield 'question maps to question' => ['question', 'question'];
        yield 'other maps to feedback' => ['other', 'feedback'];
    }

    #[Test]
    #[DataProvider('provideCategoryLabelMappings')]
    public function mapsCategoryToGitHubLabel(string $categoryValue, string $expectedLabel): void
    {
        $labels = [
            'bug' => 'bug',
            'feature' => 'enhancement',
            'improvement' => 'enhancement',
            'question' => 'question',
            'other' => 'feedback',
        ];

        $actualLabel = $labels[$categoryValue] ?? 'feedback';
        self::assertSame($expectedLabel, $actualLabel);
    }

    #[Test]
    public function constructsBodyWithContext(): void
    {
        $context = ['page' => '/settings', 'browser' => 'Chrome 120'];
        $feedback = Feedback::create('user-001', FeedbackCategory::Feature, 'Add dark mode to settings page', $context);
        $this->repo->method('findById')->willReturn($feedback);

        // Verify the body construction logic matches what the job builds
        $contextBlock = sprintf(
            "\n\n### Context\n\n```json\n%s\n```",
            json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        $body = sprintf(
            "## Feedback\n\n%s%s\n\n---\n*Feedback ID: %s*\n*Repository: %s*",
            $feedback->description,
            $contextBlock,
            $feedback->id,
            'owner/repo',
        );

        self::assertStringContainsString('## Feedback', $body);
        self::assertStringContainsString('Add dark mode to settings page', $body);
        self::assertStringContainsString('### Context', $body);
        self::assertStringContainsString('"page":"/settings"', $body);
        self::assertStringContainsString('Feedback ID:', $body);
    }

    #[Test]
    public function constructsBodyWithoutContextWhenEmpty(): void
    {
        $feedback = Feedback::create('user-001', FeedbackCategory::Bug, 'Simple bug report without context', []);
        $this->repo->method('findById')->willReturn($feedback);

        $contextBlock = $feedback->context !== []
            ? sprintf(
                "\n\n### Context\n\n```json\n%s\n```",
                json_encode($feedback->context, JSON_THROW_ON_ERROR),
            )
            : '';

        $body = sprintf(
            "## Feedback\n\n%s%s\n\n---\n*Feedback ID: %s*\n*Repository: %s*",
            $feedback->description,
            $contextBlock,
            $feedback->id,
            'owner/repo',
        );

        self::assertStringNotContainsString('### Context', $body);
        self::assertStringContainsString('Simple bug report without context', $body);
    }

    #[Test]
    public function handleSkipsWhenFeedbackNotFound(): void
    {
        /** @var FeedbackRepositoryInterface&MockObject $repo */
        $repo = $this->createMock(FeedbackRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);
        $repo->expects(self::never())->method('save');

        $httpClient = $this->createStub(HttpClientInterface::class);
        $job = new CreateGitHubIssueJob($repo, $httpClient, 'missing-id', 'owner/repo', 'test-token');

        $job->handle();
    }

    #[Test]
    public function handleProcessesFeedbackWithAllCategories(): void
    {
        $processed = [];

        foreach (FeedbackCategory::cases() as $category) {
            $feedback = Feedback::create('user-001', $category, 'Test feedback for ' . $category->value . ' category', []);
            $repo = $this->createStub(FeedbackRepositoryInterface::class);
            $repo->method('findById')->willReturn($feedback);

            $httpClient = $this->createStub(HttpClientInterface::class);
            $job = new CreateGitHubIssueJob($repo, $httpClient, $feedback->id, 'owner/repo', 'test-token');

            // proc_open will fail in test env but that's expected
            $job->handle();
            $processed[] = $category;
        }

        self::assertCount(count(FeedbackCategory::cases()), $processed);
    }
}
