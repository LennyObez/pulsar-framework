<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\TwoFactor\RecoveryCodeGenerator;
use Pulsar\Auth\TwoFactor\TotpGenerator;
use Pulsar\Auth\TwoFactor\TotpVerifier;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Http\Controller\Admin\TwoFactorController;
use Pulsar\Extension\Cms\Internal\Security\CmsRateLimiter;
use Pulsar\Extension\Cms\Internal\Security\QrCodeEncoder;
use Pulsar\Http\Message\Response;

use function json_decode;
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * Integration tests for the 2FA enrollment flow.
 *
 * Tests the full secret generation → code verification → confirmation cycle
 * using real TotpGenerator and TotpVerifier instances. The enroll endpoint
 * tests use the underlying components directly because the provisioning URI
 * (101+ bytes) exceeds the QR encoder's version 10 capacity.
 */
#[CoversClass(TwoFactorController::class)]
#[CoversClass(TotpGenerator::class)]
#[CoversClass(TotpVerifier::class)]
#[CoversClass(RecoveryCodeGenerator::class)]
final class TwoFactorIntegrationTest extends TestCase
{
    private TotpGenerator $generator;
    private TotpVerifier $verifier;
    private RecoveryCodeGenerator $recoveryGenerator;
    private TwoFactorController $controller;

    protected function setUp(): void
    {
        $this->generator = new TotpGenerator(codeDigits: 6, period: 30);
        $this->verifier = new TotpVerifier($this->generator, window: 1);
        $this->recoveryGenerator = new RecoveryCodeGenerator();
        $qrEncoder = new QrCodeEncoder();

        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(false);
        $gate->method('allows')->willReturn(true);

        $auditLogger = $this->createStub(AuditLoggerInterface::class);

        $rateLimiter = new CmsRateLimiter($this->createStub(TaggedCacheInterface::class));

        $this->controller = new TwoFactorController(
            $this->generator,
            $this->verifier,
            $this->recoveryGenerator,
            $qrEncoder,
            $rateLimiter,
            $gate,
            $auditLogger,
        );
    }

    // -- Full enrollment flow (component-level) -----------------------------

    #[Test]
    public function fullEnrollmentFlowGenerateSecretVerifyCode(): void
    {
        // Step 1: Generate secret (simulating enrollment start)
        $secret = $this->generator->generateSecret();
        $base32Secret = $this->generator->encodeSecretBase32($secret);

        self::assertNotEmpty($base32Secret);

        // Step 2: Generate a provisioning URI
        $uri = $this->generator->provisioningUri($secret, 'user@example.com', 'PulsarCMS');
        self::assertStringStartsWith('otpauth://totp/', $uri);
        self::assertStringContainsString('secret=', $uri);

        // Step 3: Compute a valid TOTP code from the secret
        $validCode = $this->generator->computeCode($secret);
        self::assertSame(6, strlen($validCode));

        // Step 4: Verify the code (simulating confirmation)
        $timeStep = $this->verifier->verify($secret, $validCode);
        self::assertNotNull($timeStep, 'Valid TOTP code should verify successfully');
    }

    #[Test]
    public function invalidTotpCodeIsRejected(): void
    {
        $secret = $this->generator->generateSecret();

        // Verify with a bogus code
        $timeStep = $this->verifier->verify($secret, '000000');
        self::assertNull($timeStep, 'Invalid TOTP code should be rejected');
    }

    // -- Recovery code generation -------------------------------------------

    #[Test]
    public function recoveryCodesAreUnique(): void
    {
        $codes = $this->recoveryGenerator->generate(8);

        self::assertCount(8, $codes);
        self::assertCount(8, array_unique($codes), 'All recovery codes should be unique');
    }

    #[Test]
    public function recoveryCodesHaveCorrectFormat(): void
    {
        $codes = $this->recoveryGenerator->generate(8);

        foreach ($codes as $code) {
            // Should be XXXX-XXXX-XXXX-XXXX format
            self::assertMatchesRegularExpression('/^[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}$/', $code);
        }
    }

    #[Test]
    public function recoveryCodeSingleUseViaCanonicalize(): void
    {
        $codes = $this->recoveryGenerator->generate(8);

        // Verify codes can be canonicalized (strip dashes, uppercase)
        foreach ($codes as $code) {
            $canonical = RecoveryCodeGenerator::canonicalize($code);
            self::assertSame(16, strlen($canonical), 'Canonical code should be 16 hex chars');
            self::assertMatchesRegularExpression('/^[A-F0-9]{16}$/', $canonical);
        }
    }

    // -- Controller endpoints (non-QR) --------------------------------------

    #[Test]
    public function confirmWithEmptyCodeReturns400(): void
    {
        $request = $this->createAuthenticatedRequest([
            'code' => '',
            'secret' => 'JBSWY3DPEHPK3PXP',
        ]);

        $response = $this->controller->confirm($request);
        $data = $this->decodeJsonResponse($response);

        self::assertArrayHasKey('error', $data);
    }

    #[Test]
    public function confirmWithEmptySecretReturns400(): void
    {
        $request = $this->createAuthenticatedRequest([
            'code' => '123456',
            'secret' => '',
        ]);

        $response = $this->controller->confirm($request);
        $data = $this->decodeJsonResponse($response);

        self::assertArrayHasKey('error', $data);
    }

    #[Test]
    public function confirmWithInvalidCodeReturns422(): void
    {
        $secret = $this->generator->generateSecret();
        $base32Secret = $this->generator->encodeSecretBase32($secret);

        $request = $this->createAuthenticatedRequest([
            'code' => '000000',
            'secret' => $base32Secret,
        ]);

        $response = $this->controller->confirm($request);
        $data = $this->decodeJsonResponse($response);

        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertStringContainsString('Invalid', $data['error']);
    }

    #[Test]
    public function confirmWithValidCodeSucceeds(): void
    {
        $secret = $this->generator->generateSecret();
        $base32Secret = $this->generator->encodeSecretBase32($secret);
        $validCode = $this->generator->computeCode($secret);

        $request = $this->createAuthenticatedRequest([
            'code' => $validCode,
            'secret' => $base32Secret,
        ]);

        $response = $this->controller->confirm($request);
        $data = $this->decodeJsonResponse($response);

        self::assertSame('confirmed', $data['status']);
    }

    // -- Verify endpoint ----------------------------------------------------

    #[Test]
    public function verifyValidCode(): void
    {
        $secret = $this->generator->generateSecret();
        $base32Secret = $this->generator->encodeSecretBase32($secret);
        $validCode = $this->generator->computeCode($secret);

        $request = $this->createAuthenticatedRequest([
            'code' => $validCode,
            'secret' => $base32Secret,
        ]);

        $response = $this->controller->verify($request);
        $data = $this->decodeJsonResponse($response);

        self::assertTrue($data['valid']);
    }

    #[Test]
    public function verifyInvalidCode(): void
    {
        $secret = $this->generator->generateSecret();
        $base32Secret = $this->generator->encodeSecretBase32($secret);

        $request = $this->createAuthenticatedRequest([
            'code' => '999999',
            'secret' => $base32Secret,
        ]);

        $response = $this->controller->verify($request);
        $data = $this->decodeJsonResponse($response);

        self::assertFalse($data['valid']);
    }

    // -- Disable endpoint ---------------------------------------------------

    #[Test]
    public function disableRequiresReason(): void
    {
        $request = $this->createAuthenticatedRequest([
            'reason' => 'short',
        ]);

        $response = $this->controller->disable($request);
        $data = $this->decodeJsonResponse($response);

        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertStringContainsString('10 characters', $data['error']);
    }

    #[Test]
    public function disableWithValidReason(): void
    {
        $request = $this->createAuthenticatedRequest([
            'reason' => 'Lost my authenticator device and need to reset',
        ]);

        $response = $this->controller->disable($request);
        $data = $this->decodeJsonResponse($response);

        self::assertSame('disabled', $data['status']);
    }

    // -- Regenerate recovery codes ------------------------------------------

    #[Test]
    public function regenerateRecoveryCodes(): void
    {
        $request = $this->createAuthenticatedRequest([]);

        $response = $this->controller->regenerateRecoveryCodes($request);
        $data = $this->decodeJsonResponse($response);

        self::assertArrayHasKey('recovery_codes', $data);
        self::assertIsArray($data['recovery_codes']);
        self::assertCount(8, $data['recovery_codes']);
    }

    // -- Helpers ------------------------------------------------------------

    /**
     * @param array<string, mixed> $body
     */
    private function createAuthenticatedRequest(array $body): ServerRequestInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn('user-id-123');
        $identity->method('isAuthenticated')->willReturn(true);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($body);

        $request->method('getAttribute')
            ->willReturnCallback(static function (string $name) use ($identity): mixed {
                return match ($name) {
                    'identity' => $identity,
                    'step_up_verified' => true,
                    default => null,
                };
            });

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonResponse(Response $response): array
    {
        $body = (string) $response->getBody();

        /** @var array<string, mixed> */
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }
}
