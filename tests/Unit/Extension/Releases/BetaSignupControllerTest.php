<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Releases;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Extension\Releases\BetaSignup;
use Pulsar\Extension\Releases\BetaSignupRepositoryInterface;
use Pulsar\Extension\Releases\DeviceType;
use Pulsar\Extension\Releases\Http\Controller\Api\BetaSignupController;
use Pulsar\Extension\Releases\Internal\ReleaseService;
use Pulsar\Extension\Releases\ReleaseRepositoryInterface;
use Pulsar\Http\Message\Response;

use function json_decode;

use const JSON_THROW_ON_ERROR;

final class BetaSignupControllerTest extends TestCase
{
    private ReleaseRepositoryInterface&Stub $releaseRepo;
    private BetaSignupRepositoryInterface&Stub $betaSignupRepo;
    private BetaSignupController $controller;

    protected function setUp(): void
    {
        $this->releaseRepo = $this->createStub(ReleaseRepositoryInterface::class);
        $this->betaSignupRepo = $this->createStub(BetaSignupRepositoryInterface::class);
        $service = new ReleaseService($this->releaseRepo, $this->betaSignupRepo);
        $this->controller = new BetaSignupController($service);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonResponse(Response $response): array
    {
        $decoded = json_decode($response->getBody()->__toString(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> */
        return $decoded;
    }

    #[Test]
    public function signupReturns201ForValidData(): void
    {
        $this->betaSignupRepo->method('findByEmail')->willReturn(null);
        $this->betaSignupRepo->method('countByEmailToday')->willReturn(0);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'email' => 'user@example.com',
            'device_type' => 'android',
            'camera_brands' => ['Canon', 'Sony'],
        ]);

        $response = $this->controller->signup($request);

        self::assertSame(201, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertSame('user@example.com', $data['email']);
        self::assertSame('android', $data['device_type']);
        self::assertSame(['Canon', 'Sony'], $data['camera_brands']);
        self::assertIsString($data['id']);
        self::assertIsString($data['signed_up_at']);
    }

    #[Test]
    public function signupReturns422ForEmptyEmail(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'email' => '',
            'device_type' => 'android',
        ]);

        $response = $this->controller->signup($request);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('Validation failed', $body['error']);
        $details = $body['details'];
        self::assertIsArray($details);
        $emailError = $details['email'];
        self::assertIsString($emailError);
        self::assertStringContainsString('required', $emailError);
    }

    #[Test]
    public function signupReturns422ForMissingEmail(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'device_type' => 'ios',
        ]);

        $response = $this->controller->signup($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function signupReturns422ForInvalidDeviceType(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'email' => 'user@example.com',
            'device_type' => 'windows',
        ]);

        $response = $this->controller->signup($request);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('Validation failed', $body['error']);
        $details = $body['details'];
        self::assertIsArray($details);
        $deviceTypeError = $details['device_type'];
        self::assertIsString($deviceTypeError);
        self::assertStringContainsString('Invalid device type', $deviceTypeError);
    }

    #[Test]
    public function signupReturns422ForEmptyDeviceType(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'email' => 'user@example.com',
            'device_type' => '',
        ]);

        $response = $this->controller->signup($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function signupReturns422ForInvalidEmail(): void
    {
        $this->betaSignupRepo->method('findByEmail')->willReturn(null);
        $this->betaSignupRepo->method('countByEmailToday')->willReturn(0);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'email' => 'not-an-email',
            'device_type' => 'ios',
        ]);

        $response = $this->controller->signup($request);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('Validation failed', $body['error']);
        $details = $body['details'];
        self::assertIsArray($details);
        self::assertArrayHasKey('email', $details);
    }

    #[Test]
    public function signupReturns422ForAlreadyRegisteredEmail(): void
    {
        $existing = BetaSignup::create('user@example.com', DeviceType::Android, []);
        $this->betaSignupRepo->method('findByEmail')->willReturn($existing);
        $this->betaSignupRepo->method('countByEmailToday')->willReturn(0);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'email' => 'user@example.com',
            'device_type' => 'android',
        ]);

        $response = $this->controller->signup($request);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $details = $body['details'];
        self::assertIsArray($details);
        $emailError = $details['email'];
        self::assertIsString($emailError);
        self::assertStringContainsString('already registered', $emailError);
    }

    #[Test]
    public function signupReturns429WhenRateLimitExceeded(): void
    {
        $this->betaSignupRepo->method('findByEmail')->willReturn(null);
        $this->betaSignupRepo->method('countByEmailToday')->willReturn(3);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'email' => 'user@example.com',
            'device_type' => 'both',
        ]);

        $response = $this->controller->signup($request);

        self::assertSame(429, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $error = $body['error'];
        self::assertIsString($error);
        self::assertStringContainsString('Rate limit', $error);
    }

    #[Test]
    public function signupWithNullBodyReturns422(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(null);

        $response = $this->controller->signup($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function signupFiltersInvalidCameraBrands(): void
    {
        $this->betaSignupRepo->method('findByEmail')->willReturn(null);
        $this->betaSignupRepo->method('countByEmailToday')->willReturn(0);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'email' => 'user@example.com',
            'device_type' => 'android',
            'camera_brands' => ['Canon', '', 42, 'Sony', null],
        ]);

        $response = $this->controller->signup($request);

        self::assertSame(201, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertSame(['Canon', 'Sony'], $data['camera_brands']);
    }

    #[Test]
    public function signupWithNoCameraBrandsDefaultsToEmpty(): void
    {
        $this->betaSignupRepo->method('findByEmail')->willReturn(null);
        $this->betaSignupRepo->method('countByEmailToday')->willReturn(0);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'email' => 'user@example.com',
            'device_type' => 'both',
        ]);

        $response = $this->controller->signup($request);

        self::assertSame(201, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertSame([], $data['camera_brands']);
    }

    #[Test]
    public function signupAcceptsBothDeviceType(): void
    {
        $this->betaSignupRepo->method('findByEmail')->willReturn(null);
        $this->betaSignupRepo->method('countByEmailToday')->willReturn(0);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'email' => 'user@example.com',
            'device_type' => 'both',
        ]);

        $response = $this->controller->signup($request);

        self::assertSame(201, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertSame('both', $data['device_type']);
    }
}
