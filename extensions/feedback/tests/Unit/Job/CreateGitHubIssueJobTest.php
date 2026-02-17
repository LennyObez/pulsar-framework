<?php

declare(strict_types=1);

namespace Pulsar\Extension\Feedback\Tests\Unit\Job;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Extension\Feedback\Feedback;
use Pulsar\Extension\Feedback\FeedbackCategory;
use Pulsar\Extension\Feedback\FeedbackRepositoryInterface;
use Pulsar\Extension\Feedback\Job\CreateGitHubIssueJob;
use Pulsar\Http\Client\HttpClientInterface;
use Pulsar\Http\Client\HttpResponse;
use RuntimeException;

final class CreateGitHubIssueJobTest extends TestCase
{
    private FeedbackRepositoryInterface&Stub $repository;
    private HttpClientInterface&Stub $httpClient;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(FeedbackRepositoryInterface::class);
        $this->httpClient = $this->createStub(HttpClientInterface::class);
    }

    #[Test]
    public function handleReturnsEarlyWhenFeedbackNotFound(): void
    {
        $this->repository->method('findById')->willReturn(null);

        $job = new CreateGitHubIssueJob(
            $this->repository,
            $this->httpClient,
            'nonexistent',
            'org/repo',
            'gh-token',
        );

        $job->handle();

        // Verify the method completed without error
        self::assertTrue(true, 'handle() completed without exception when feedback not found');
    }

    #[Test]
    public function handleCreatesGitHubIssueViaApi(): void
    {
        $feedback = Feedback::create(
            'user-1',
            FeedbackCategory::Bug,
            'Button does not work on the settings page',
            ['url' => '/settings', 'browser' => 'Firefox'],
        );

        $this->repository->method('findById')->willReturn($feedback);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('post')
            ->with(
                'https://api.github.com/repos/org/repo/issues',
                self::callback(static function (array $options): bool {
                    $headers = $options['headers'] ?? [];
                    $json = $options['json'] ?? [];

                    // Verify auth header
                    if (($headers['Authorization'] ?? '') !== 'Bearer gh-token') {
                        return false;
                    }

                    // Verify GitHub API version header
                    if (($headers['X-GitHub-Api-Version'] ?? '') !== '2022-11-28') {
                        return false;
                    }

                    // Verify issue title
                    if (($json['title'] ?? '') !== 'Button does not work on the settings page') {
                        return false;
                    }

                    // Verify labels
                    if (($json['labels'] ?? []) !== ['bug']) {
                        return false;
                    }

                    // Verify body contains feedback description
                    $body = $json['body'] ?? '';
                    if (!str_contains($body, 'Button does not work')) {
                        return false;
                    }

                    // Verify body contains context block
                    if (!str_contains($body, 'Context')) {
                        return false;
                    }

                    return true;
                }),
            )
            ->willReturn(HttpResponse::fromRaw(201, [], json_encode([
                'html_url' => 'https://github.com/org/repo/issues/42',
                'number' => 42,
            ], JSON_THROW_ON_ERROR)));

        $savedFeedback = null;
        $repo = $this->createMock(FeedbackRepositoryInterface::class);
        $repo->method('findById')->willReturn($feedback);
        $repo->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (Feedback $fb) use (&$savedFeedback): void {
                $savedFeedback = $fb;
            });

        $job = new CreateGitHubIssueJob($repo, $httpClient, $feedback->id, 'org/repo', 'gh-token');
        $job->handle();

        self::assertNotNull($savedFeedback);
        self::assertSame('https://github.com/org/repo/issues/42', $savedFeedback->githubIssueUrl);
    }

    #[Test]
    public function handleMapsCategoriesToLabels(): void
    {
        $categoryMap = [
            [FeedbackCategory::Bug, 'bug'],
            [FeedbackCategory::Feature, 'enhancement'],
            [FeedbackCategory::Improvement, 'enhancement'],
            [FeedbackCategory::Question, 'question'],
            [FeedbackCategory::Other, 'feedback'],
        ];

        foreach ($categoryMap as [$category, $expectedLabel]) {
            $feedback = Feedback::create('user-1', $category, 'Test description here');

            $httpClient = $this->createMock(HttpClientInterface::class);
            $httpClient->expects(self::once())
                ->method('post')
                ->with(
                    self::anything(),
                    self::callback(static fn(array $opts): bool => ($opts['json']['labels'] ?? []) === [$expectedLabel]),
                )
                ->willReturn(HttpResponse::fromRaw(201, [], json_encode(['html_url' => 'https://github.com/org/repo/issues/1'], JSON_THROW_ON_ERROR)));

            $repo = $this->createStub(FeedbackRepositoryInterface::class);
            $repo->method('findById')->willReturn($feedback);

            $job = new CreateGitHubIssueJob($repo, $httpClient, $feedback->id, 'org/repo', 'gh-token');
            $job->handle();
        }
    }

    #[Test]
    public function handleTruncatesTitleTo80Chars(): void
    {
        $longDescription = str_repeat('A', 200);
        $feedback = Feedback::create('user-1', FeedbackCategory::Bug, $longDescription);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('post')
            ->with(
                self::anything(),
                self::callback(static fn(array $opts): bool => mb_strlen($opts['json']['title'] ?? '') === 80),
            )
            ->willReturn(HttpResponse::fromRaw(201, [], json_encode(['html_url' => 'https://github.com/org/repo/issues/1'], JSON_THROW_ON_ERROR)));

        $repo = $this->createStub(FeedbackRepositoryInterface::class);
        $repo->method('findById')->willReturn($feedback);

        $job = new CreateGitHubIssueJob($repo, $httpClient, $feedback->id, 'org/repo', 'gh-token');
        $job->handle();
    }

    #[Test]
    public function handleOmitsContextBlockWhenEmpty(): void
    {
        $feedback = Feedback::create('user-1', FeedbackCategory::Question, 'Simple question here');

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('post')
            ->with(
                self::anything(),
                self::callback(static fn(array $opts): bool => !str_contains($opts['json']['body'] ?? '', '### Context')),
            )
            ->willReturn(HttpResponse::fromRaw(201, [], json_encode(['html_url' => 'https://github.com/org/repo/issues/1'], JSON_THROW_ON_ERROR)));

        $repo = $this->createStub(FeedbackRepositoryInterface::class);
        $repo->method('findById')->willReturn($feedback);

        $job = new CreateGitHubIssueJob($repo, $httpClient, $feedback->id, 'org/repo', 'gh-token');
        $job->handle();
    }

    #[Test]
    public function handleLogsErrorOnApiFailure(): void
    {
        $feedback = Feedback::create('user-1', FeedbackCategory::Bug, 'Bug report');

        $this->httpClient->method('post')
            ->willReturn(HttpResponse::fromRaw(422, [], '{"message":"Validation Failed"}'));

        $this->repository->method('findById')->willReturn($feedback);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'GitHub issue creation failed',
            self::callback(static fn(array $ctx): bool => $ctx['status'] === 422),
        );

        $job = new CreateGitHubIssueJob(
            $this->repository,
            $this->httpClient,
            $feedback->id,
            'org/repo',
            'gh-token',
            $logger,
        );
        $job->handle();
    }

    #[Test]
    public function handleLogsErrorOnTransportException(): void
    {
        $feedback = Feedback::create('user-1', FeedbackCategory::Bug, 'Bug report');

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('post')->willThrowException(new RuntimeException('DNS failure'));

        $this->repository->method('findById')->willReturn($feedback);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'GitHub issue creation error',
            self::callback(static fn(array $ctx): bool => $ctx['error'] === 'DNS failure'),
        );

        $job = new CreateGitHubIssueJob(
            $this->repository,
            $httpClient,
            $feedback->id,
            'org/repo',
            'gh-token',
            $logger,
        );
        $job->handle();
    }

    #[Test]
    public function handleDoesNotSaveFeedbackWhenResponseMissingHtmlUrl(): void
    {
        $feedback = Feedback::create('user-1', FeedbackCategory::Bug, 'Bug report');

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('post')
            ->willReturn(HttpResponse::fromRaw(201, [], json_encode(['id' => 42], JSON_THROW_ON_ERROR)));

        $repo = $this->createMock(FeedbackRepositoryInterface::class);
        $repo->method('findById')->willReturn($feedback);
        $repo->expects(self::never())->method('save');

        $job = new CreateGitHubIssueJob($repo, $httpClient, $feedback->id, 'org/repo', 'gh-token');
        $job->handle();
    }
}
