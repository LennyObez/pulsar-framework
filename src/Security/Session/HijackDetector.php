<?php

declare(strict_types=1);

namespace Pulsar\Security\Session;

use NoDiscard;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;

use function is_string;

/**
 * Detects potential session hijacking by comparing current request
 * properties against stored session metadata.
 *
 * Checks include:
 * - IP address changes mid-session (configurable: invalidate, warn, or challenge)
 * - User-agent changes (always invalidate; indicates session replay)
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class HijackDetector
{
    public function __construct(
        private LoggerInterface $logger,
        private HijackPolicy $ipChangePolicy = HijackPolicy::Warn,
        private ?AuditLogger $auditLogger = null,
    ) {}

    /**
     * Analyze the request against session metadata for hijacking indicators.
     *
     * @return HijackVerdict The detection result with recommended action
     */
    #[NoDiscard]
    public function analyze(ServerRequestInterface $request, SessionMetadata $metadata): HijackVerdict
    {
        $currentIp = $this->extractIp($request);
        $currentUserAgent = $request->getHeaderLine('User-Agent');

        // User-agent change is always treated as hijacking (session replay)
        if ($metadata->userAgent !== '' && $currentUserAgent !== $metadata->userAgent) {
            $this->logger->warning('Session hijacking suspected: user-agent changed', [
                'session_ua' => $metadata->userAgent,
                'current_ua' => $currentUserAgent,
                'session_ip' => $metadata->ipAddress,
                'current_ip' => $currentIp,
                'user_id' => $metadata->userId,
            ]);

            $this->auditLogger?->log(
                AuditEvent::SecurityEvent,
                AuditOutcome::Denied,
                $metadata->userId,
                'session.hijack.user_agent_change',
                '',
                [
                    'current_ip' => $currentIp,
                    'session_ip' => $metadata->ipAddress,
                ],
            );

            return HijackVerdict::invalidate('User-agent changed mid-session');
        }

        // IP change detection
        if ($metadata->ipAddress !== '' && $currentIp !== '' && $currentIp !== $metadata->ipAddress) {
            $this->logger->notice('Session IP address changed', [
                'session_ip' => $metadata->ipAddress,
                'current_ip' => $currentIp,
                'policy' => $this->ipChangePolicy->value,
                'user_id' => $metadata->userId,
            ]);

            $this->auditLogger?->log(
                AuditEvent::SecurityEvent,
                AuditOutcome::Denied,
                $metadata->userId,
                'session.hijack.ip_change',
                '',
                [
                    'session_ip' => $metadata->ipAddress,
                    'current_ip' => $currentIp,
                    'policy' => $this->ipChangePolicy->value,
                ],
            );

            return match ($this->ipChangePolicy) {
                HijackPolicy::Invalidate => HijackVerdict::invalidate('IP address changed mid-session'),
                HijackPolicy::Challenge => HijackVerdict::challenge('IP address changed: MFA required'),
                HijackPolicy::Warn => HijackVerdict::warn('IP address changed mid-session'),
            };
        }

        return HijackVerdict::ok();
    }

    private function extractIp(ServerRequestInterface $request): string
    {
        /** @var mixed $ip */
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        return is_string($ip) ? $ip : '';
    }
}
