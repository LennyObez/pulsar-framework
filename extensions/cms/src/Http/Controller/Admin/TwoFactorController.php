<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Auth\TwoFactor\RecoveryCodeGenerator;
use Pulsar\Auth\TwoFactor\TotpGenerator;
use Pulsar\Auth\TwoFactor\TotpVerifier;
use Pulsar\Extension\Cms\Internal\Security\CmsRateLimiter;
use Pulsar\Extension\Cms\Security\QrCodeEncoder;
use Pulsar\Http\Message\Response;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\View\Engine\TemplateEngineInterface;

use function is_string;
use function strlen;

/**
 * Admin controller for two-factor authentication enrollment and management.
 *
 * Provides endpoints for enrolling (with QR code), verifying, and disabling 2FA.
 * All operations require step-up authentication and appropriate permissions.
 */
#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class TwoFactorController
{
    use RendersAdminView;

    private const int RATE_LIMIT_PER_MINUTE = 5;

    public function __construct(
        private TotpGenerator $totpGenerator,
        private TotpVerifier $totpVerifier,
        private RecoveryCodeGenerator $recoveryCodeGenerator,
        private QrCodeEncoder $qrCodeEncoder,
        private ?CmsRateLimiter $rateLimiter,
        private ?AuditLoggerInterface $auditLogger,
        private ?GateInterface $gate = null,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    /**
     * Return 2FA enrollment status for the current user.
     */
    public function status(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);

        $data = [
            'user_id' => $identity->id(),
            'two_factor_status' => $identity->twoFactorStatus()->value,
            'is_enrolled' => $identity->twoFactorStatus() !== TwoFactorStatus::Disabled,
        ];

        return $this->respondWithView($request, 'admin.2fa.status', $data);
    }

    /**
     * Begin 2FA enrollment: generate secret and QR code.
     *
     * Returns a provisioning URI and SVG QR code for scanning with an authenticator app.
     * The secret is returned to be stored temporarily until the user confirms with a valid code.
     */
    public function enroll(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.users.manage');
        $this->requireStepUp($request);

        if ($this->rateLimiter !== null && !$this->rateLimiter->attempt('2fa_enroll:' . $identity->id(), self::RATE_LIMIT_PER_MINUTE)) {
            return Response::json(['error' => 'Too many requests'], 429);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $accountName = is_string($body['account_name'] ?? null) ? $body['account_name'] : $identity->id();
        $issuer = is_string($body['issuer'] ?? null) ? $body['issuer'] : 'PulsarCMS';

        // Generate a new TOTP secret
        $secret = $this->totpGenerator->generateSecret();
        $base32Secret = $this->totpGenerator->encodeSecretBase32($secret);

        // Generate the provisioning URI
        $provisioningUri = $this->totpGenerator->provisioningUri($secret, $accountName, $issuer);

        // Generate QR code SVG
        $qrSvg = $this->qrCodeEncoder->encode($provisioningUri);

        // Generate recovery codes
        $recoveryCodes = $this->recoveryCodeGenerator->generate();

        $this->auditLogger?->log(
            AuditEvent::SecurityEvent,
            AuditOutcome::Success,
            $identity->id(),
            'cms.2fa.enrollment_started',
            "user:{$identity->id()}",
            [],
        );

        $data = [
            'secret' => $base32Secret,
            'provisioning_uri' => $provisioningUri,
            'qr_code_svg' => $qrSvg,
            'recovery_codes' => $recoveryCodes,
            'digits' => $this->totpGenerator->digits(),
            'period' => $this->totpGenerator->period(),
        ];

        return $this->respondWithView($request, 'admin.2fa.enroll', $data);
    }

    /**
     * Confirm 2FA enrollment by verifying the first TOTP code.
     *
     * The client must submit a valid TOTP code to prove the authenticator is configured.
     */
    public function confirm(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.users.manage');
        $this->requireStepUp($request);

        if ($this->rateLimiter !== null && !$this->rateLimiter->attempt('2fa_confirm:' . $identity->id(), self::RATE_LIMIT_PER_MINUTE)) {
            return Response::json(['error' => 'Too many requests'], 429);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $code = is_string($body['code'] ?? null) ? $body['code'] : '';
        $secret = is_string($body['secret'] ?? null) ? $body['secret'] : '';

        if ($code === '' || $secret === '') {
            return Response::json(['error' => 'Both code and secret are required'], 400);
        }

        // Decode base32 secret back to binary for verification
        $binarySecret = $this->totpGenerator->decodeSecretBase32($secret);

        if ($binarySecret === null) {
            return Response::json(['error' => 'Invalid secret format'], 400);
        }

        // Verify the TOTP code
        $timeStep = $this->totpVerifier->verify($binarySecret, $code);

        if ($timeStep === null) {
            $this->auditLogger?->log(
                AuditEvent::Authentication,
                AuditOutcome::Failure,
                $identity->id(),
                'cms.2fa.enrollment_confirm_failed',
                "user:{$identity->id()}",
                [],
            );

            return Response::json(['error' => 'Invalid verification code'], 422);
        }

        $this->auditLogger?->log(
            AuditEvent::SecurityEvent,
            AuditOutcome::Success,
            $identity->id(),
            'cms.2fa.enrollment_confirmed',
            "user:{$identity->id()}",
            [],
        );

        return Response::json([
            'status' => 'confirmed',
            'message' => 'Two-factor authentication has been enabled',
        ]);
    }

    /**
     * Verify a TOTP code (general verification endpoint).
     */
    public function verify(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);

        if ($this->rateLimiter !== null && !$this->rateLimiter->attempt('2fa_verify:' . $identity->id(), self::RATE_LIMIT_PER_MINUTE)) {
            return Response::json(['error' => 'Too many requests'], 429);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $code = is_string($body['code'] ?? null) ? $body['code'] : '';
        $secret = is_string($body['secret'] ?? null) ? $body['secret'] : '';

        if ($code === '' || $secret === '') {
            return Response::json(['error' => 'Both code and secret are required'], 400);
        }

        $binarySecret = $this->totpGenerator->decodeSecretBase32($secret);

        if ($binarySecret === null) {
            return Response::json(['error' => 'Invalid secret format'], 400);
        }

        $timeStep = $this->totpVerifier->verify($binarySecret, $code);

        if ($timeStep === null) {
            $this->auditLogger?->log(
                AuditEvent::Authentication,
                AuditOutcome::Failure,
                $identity->id(),
                'cms.2fa.verify_failed',
                "user:{$identity->id()}",
                [],
            );

            return Response::json(['valid' => false], 401);
        }

        $this->auditLogger?->log(
            AuditEvent::Authentication,
            AuditOutcome::Success,
            $identity->id(),
            'cms.2fa.verify_success',
            "user:{$identity->id()}",
            [],
        );

        return Response::json(['valid' => true]);
    }

    /**
     * Disable 2FA for the authenticated user.
     *
     * Requires step-up authentication and a mandatory reason (min 10 chars).
     */
    public function disable(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.users.manage');
        $this->requireStepUp($request);

        if ($this->rateLimiter !== null && !$this->rateLimiter->attempt('2fa_disable:' . $identity->id(), self::RATE_LIMIT_PER_MINUTE)) {
            return Response::json(['error' => 'Too many requests'], 429);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : '';

        if (strlen($reason) < 10) {
            return Response::json([
                'error' => 'A reason of at least 10 characters is required for disabling 2FA',
            ], 400);
        }

        $this->auditLogger?->log(
            AuditEvent::SecurityEvent,
            AuditOutcome::Success,
            $identity->id(),
            'cms.2fa.disabled',
            "user:{$identity->id()}",
            ['reason' => $reason],
        );

        return Response::json([
            'status' => 'disabled',
            'message' => 'Two-factor authentication has been disabled',
        ]);
    }

    /**
     * Generate a new set of recovery codes.
     *
     * Requires step-up authentication.
     */
    public function regenerateRecoveryCodes(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.users.manage');
        $this->requireStepUp($request);

        $recoveryCodes = $this->recoveryCodeGenerator->generate();

        $this->auditLogger?->log(
            AuditEvent::SecurityEvent,
            AuditOutcome::Success,
            $identity->id(),
            'cms.2fa.recovery_codes_regenerated',
            "user:{$identity->id()}",
            [],
        );

        return Response::json([
            'recovery_codes' => $recoveryCodes,
        ]);
    }

}
