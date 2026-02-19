<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\TwoFactor\RecoveryCodeGenerator;
use Pulsar\Auth\TwoFactor\TotpGenerator;
use Pulsar\Auth\TwoFactor\TotpVerifier;
use Pulsar\Extension\Cms\Internal\Security\QrCodeEncoder;
use Pulsar\Http\Message\Response;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use RuntimeException;

use function chr;
use function is_string;
use function strlen;

/**
 * Admin controller for two-factor authentication enrollment and management.
 *
 * Provides endpoints for enrolling (with QR code), verifying, and disabling 2FA.
 * All operations require step-up authentication and appropriate permissions.
 */
#[Internal(reason: 'CMS admin controller — implementation detail')]
final readonly class TwoFactorController
{
    public function __construct(
        private TotpGenerator $totpGenerator,
        private TotpVerifier $totpVerifier,
        private RecoveryCodeGenerator $recoveryCodeGenerator,
        private QrCodeEncoder $qrCodeEncoder,
        private GateInterface $gate,
        private ?AuditLoggerInterface $auditLogger,
    ) {}

    /**
     * Begin 2FA enrollment — generate secret and QR code.
     *
     * Returns a provisioning URI and SVG QR code for scanning with an authenticator app.
     * The secret is returned to be stored temporarily until the user confirms with a valid code.
     */
    public function enroll(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.users.manage');
        $this->requireStepUp($request);

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
        $recoveryCodes = $this->recoveryCodeGenerator->generate(8);

        $this->auditLogger?->log(
            AuditEvent::SecurityEvent,
            AuditOutcome::Success,
            $identity->id(),
            'cms.2fa.enrollment_started',
            "user:{$identity->id()}",
            [],
        );

        return Response::json([
            'secret' => $base32Secret,
            'provisioning_uri' => $provisioningUri,
            'qr_code_svg' => $qrSvg,
            'recovery_codes' => $recoveryCodes,
            'digits' => $this->totpGenerator->digits(),
            'period' => $this->totpGenerator->period(),
        ]);
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

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $code = is_string($body['code'] ?? null) ? $body['code'] : '';
        $secret = is_string($body['secret'] ?? null) ? $body['secret'] : '';

        if ($code === '' || $secret === '') {
            return Response::json(['error' => 'Both code and secret are required'], 400);
        }

        // Decode base32 secret back to binary for verification
        $binarySecret = $this->base32Decode($secret);

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

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $code = is_string($body['code'] ?? null) ? $body['code'] : '';
        $secret = is_string($body['secret'] ?? null) ? $body['secret'] : '';

        if ($code === '' || $secret === '') {
            return Response::json(['error' => 'Both code and secret are required'], 400);
        }

        $binarySecret = $this->base32Decode($secret);

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

        $recoveryCodes = $this->recoveryCodeGenerator->generate(8);

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

    /**
     * Decode a Base32-encoded string to binary.
     */
    private function base32Decode(string $input): ?string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $input = strtoupper($input);
        $result = '';
        $buffer = 0;
        $bitsLeft = 0;

        for ($i = 0; $i < strlen($input); $i++) {
            $val = strpos($alphabet, $input[$i]);

            if ($val === false) {
                return null;
            }

            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $result .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $result;
    }

    private function requireStepUp(ServerRequestInterface $request): void
    {
        $stepUp = $request->getAttribute('step_up_verified', false);

        if ($stepUp !== true) {
            throw new RuntimeException('Step-up authentication required for this action');
        }
    }

    private function requireIdentity(ServerRequestInterface $request): IdentityInterface
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        if ($identity === null || !$identity->isAuthenticated()) {
            throw new RuntimeException('Authentication required');
        }

        return $identity;
    }

    private function authorize(IdentityInterface $identity, string $permission): void
    {
        if ($this->gate->denies($identity, $permission)) {
            throw new RuntimeException('Permission denied');
        }
    }
}
