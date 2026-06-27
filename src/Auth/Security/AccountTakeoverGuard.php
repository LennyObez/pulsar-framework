<?php

declare(strict_types=1);

namespace Pulsar\Auth\Security;

use NoDiscard;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Http\TrustedProxy;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Session\SessionMetadata;

use function in_array;
use function is_string;
use function time;

/**
 * Guards against account takeover by requiring re-authentication for
 * sensitive operations and detecting suspicious credential changes.
 *
 * Monitors: password change, email change, MFA disable, recovery code
 * regeneration. Detects credential changes from new IP/device and
 * triggers elevated alerts.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AccountTakeoverGuard
{
    private const int DEFAULT_REAUTH_WINDOW = 300; // 5 minutes

    /**
     * @param list<SensitiveOperation> $requiresReauth Operations requiring re-authentication
     */
    public function __construct(
        private LoggerInterface $logger,
        private int $reauthWindowSeconds = self::DEFAULT_REAUTH_WINDOW,
        private array $requiresReauth = [
            SensitiveOperation::PasswordChange,
            SensitiveOperation::EmailChange,
            SensitiveOperation::MfaDisable,
            SensitiveOperation::RecoveryCodeRegenerate,
            SensitiveOperation::AccountDelete,
        ],
        private ?AuditLogger $auditLogger = null,
        private ?TrustedProxy $trustedProxy = null,
    ) {}

    /**
     * Check whether the given sensitive operation is allowed without
     * re-authentication, based on when the user last authenticated.
     */
    #[NoDiscard]
    public function requiresReauthentication(
        SensitiveOperation $operation,
        ?int $lastAuthenticatedAt,
    ): bool {
        if (!in_array($operation, $this->requiresReauth, true)) {
            return false;
        }

        if ($lastAuthenticatedAt === null) {
            return true;
        }

        return (time() - $lastAuthenticatedAt) > $this->reauthWindowSeconds;
    }

    /**
     * Evaluate whether a sensitive operation from the current request
     * is suspicious based on IP/device changes relative to session metadata.
     */
    #[NoDiscard]
    public function evaluate(
        SensitiveOperation $operation,
        ServerRequestInterface $request,
        SessionMetadata $sessionMeta,
    ): TakeoverRisk {
        $currentIp = $this->extractIp($request);
        $currentUa = $request->getHeaderLine('User-Agent');

        $ipChanged = $sessionMeta->ipAddress !== '' && $currentIp !== $sessionMeta->ipAddress;
        $uaChanged = $sessionMeta->userAgent !== '' && $currentUa !== $sessionMeta->userAgent;

        if ($ipChanged && $uaChanged) {
            $this->logger->warning('Account takeover risk: both IP and device changed during sensitive operation', [
                'operation' => $operation->value,
                'session_ip' => $sessionMeta->ipAddress,
                'current_ip' => $currentIp,
                'user_id' => $sessionMeta->userId,
            ]);

            $this->auditLogger?->log(
                AuditEvent::SecurityEvent,
                AuditOutcome::Denied,
                $sessionMeta->userId,
                'auth.takeover.high_risk',
                $operation->value,
                [
                    'session_ip' => $sessionMeta->ipAddress,
                    'current_ip' => $currentIp,
                ],
            );

            return TakeoverRisk::high('IP and device changed during ' . $operation->value);
        }

        if ($ipChanged) {
            $this->logger->notice('Account takeover risk: IP changed during sensitive operation', [
                'operation' => $operation->value,
                'session_ip' => $sessionMeta->ipAddress,
                'current_ip' => $currentIp,
                'user_id' => $sessionMeta->userId,
            ]);

            $this->auditLogger?->log(
                AuditEvent::SecurityEvent,
                AuditOutcome::Denied,
                $sessionMeta->userId,
                'auth.takeover.elevated_risk',
                $operation->value,
                ['current_ip' => $currentIp],
            );

            return TakeoverRisk::elevated('IP changed during ' . $operation->value);
        }

        return TakeoverRisk::low();
    }

    private function extractIp(ServerRequestInterface $request): string
    {
        if ($this->trustedProxy !== null) {
            return $this->trustedProxy->resolveClientIp($request);
        }

        /** @var mixed $ip */
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        return is_string($ip) ? $ip : '';
    }
}
