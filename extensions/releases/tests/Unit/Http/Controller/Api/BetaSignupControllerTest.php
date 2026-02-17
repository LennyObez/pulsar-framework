<?php

declare(strict_types=1);

namespace Pulsar\Extension\Releases\Tests\Unit\Http\Controller\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Extension\Releases\BetaSignupRepositoryInterface;
use Pulsar\Extension\Releases\Http\Controller\Api\BetaSignupController;
use Pulsar\Extension\Releases\Internal\ReleaseService;
use Pulsar\Extension\Releases\ReleaseRepositoryInterface;

use function json_decode;

#[CoversClass(BetaSignupController::class)]
final class BetaSignupControllerTest extends TestCase
{
    private BetaSignupRepositoryInterface&Stub $betaRepo;
    private ReleaseService $service;
    private BetaSignupController $controller;

    protected function setUp(): void
    {
        $releaseRepo = $this->createStub(ReleaseRepositoryInterface::class);
        $this->betaRepo = $this->createStub(BetaSignupRepositoryInterface::class);
        $this->service = new ReleaseService($releaseRepo, $this->betaRepo);
        $this->controller = new BetaSignupController($this->service);
    }

    #[Test]
    public function signupReturns422WhenEmailMissing(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['device_type' => 'android']);

        $response = $this->controller->signup($request);

        self::assertSame(422, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('required', $body['details']['email']);
    }

    #[Test]
    public function signupReturns422WhenDeviceTypeInvalid(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'email' => 'test@example.com',
            'device_type' => 'windows',
        ]);

        $response = $this->controller->signup($request);

        self::assertSame(422, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('Invalid device type', $body['details']['device_type']);
    }

    #[Test]
    public function signupReturns201OnSuccess(): void
    {
        $this->betaRepo->method('findByEmail')->willReturn(null);
        $this->betaRepo->method('countByEmailToday')->willReturn(0);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'email' => 'test@example.com',
            'device_type' => 'android',
            'camera_brands' => ['Canon', 'Sony'],
        ]);

        $response = $this->controller->signup($request);

        self::assertSame(201, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('test@example.com', $body['data']['email']);
        self::assertSame('android', $body['data']['device_type']);
        self::assertSame(['Canon', 'Sony'], $body['data']['camera_brands']);
    }

    #[Test]
    public function signupReturns429WhenRateLimited(): void
    {
        $this->betaRepo->method('findByEmail')->willReturn(null);
        $this->betaRepo->method('countByEmailToday')->willReturn(3);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'email' => 'spammer@example.com',
            'device_type' => 'ios',
        ]);

        $response = $this->controller->signup($request);

        self::assertSame(429, $response->getStatusCode());
    }

    #[Test]
    public function signupReturns422WhenEmailInvalid(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'email' => 'not-an-email',
            'device_type' => 'both',
        ]);

        $response = $this->controller->signup($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function signupFiltersNonStringCameraBrands(): void
    {
        $this->betaRepo->method('findByEmail')->willReturn(null);
        $this->betaRepo->method('countByEmailToday')->willReturn(0);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'email' => 'filter-test@example.com',
            'device_type' => 'both',
            'camera_brands' => ['Canon', '', 123, null],
        ]);

        $response = $this->controller->signup($request);

        self::assertSame(201, $response->getStatusCode());
    }

    #[Test]
    public function signupHandlesNullBody(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(null);

        $response = $this->controller->signup($request);

        // Should return 422 for missing email
        self::assertSame(422, $response->getStatusCode());
    }
}
