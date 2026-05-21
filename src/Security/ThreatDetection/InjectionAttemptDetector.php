<?php

declare(strict_types=1);

namespace Pulsar\Security\ThreatDetection;

use Override;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;

use function is_array;
use function is_string;
use function preg_match;
use function strtolower;

/**
 * Detects SQL injection, XSS, and path traversal attempts in request parameters.
 *
 * This detector operates at the detection/logging layer, not the prevention layer.
 * Even when attacks are blocked by other mechanisms (parameterized queries, output
 * escaping), this detector records the attempt for incident reporting.
 *
 * Compliance: DORA Art.17 (incident detection), NIS2 Art.21(b),
 * PCI-DSS Req.6.4/11 (security monitoring), ISO 27001 A.8.16.
 * @api
 */
#[Api(since: '1.0.0')]
final class InjectionAttemptDetector implements ThreatDetectorInterface
{
    /**
     * SQL injection patterns.
     *
     * @var list<string>
     */
    private const array SQL_PATTERNS = [
        '/(\bunion\b\s+\bselect\b)/i',
        '/(\bselect\b\s+.*\bfrom\b)/i',
        '/(\binsert\b\s+\binto\b)/i',
        '/(\bdelete\b\s+\bfrom\b)/i',
        '/(\bdrop\b\s+\btable\b)/i',
        '/(\bor\b\s+[\'"]?\d+[\'"]?\s*=\s*[\'"]?\d+[\'"]?)/i',
        '/(\band\b\s+[\'"]?\d+[\'"]?\s*=\s*[\'"]?\d+[\'"]?)/i',
        '/(;\s*(drop|alter|create|truncate|exec)\b)/i',
        '/(--\s|\/\*|\*\/|#\s)/i',
        '/(\bwaitfor\b\s+\bdelay\b)/i',
        '/(\bbenchmark\b\s*\()/i',
        '/(\bsleep\b\s*\(\s*\d)/i',
    ];

    /**
     * XSS patterns.
     *
     * @var list<string>
     */
    private const array XSS_PATTERNS = [
        '/<\s*script\b/i',
        '/\bon\w+\s*=/i',
        '/javascript\s*:/i',
        '/vbscript\s*:/i',
        '/data\s*:\s*text\/html/i',
        '/<\s*iframe\b/i',
        '/<\s*object\b/i',
        '/<\s*embed\b/i',
        '/<\s*svg\b[^>]*\bon/i',
        '/expression\s*\(/i',
    ];

    /**
     * Path traversal patterns.
     *
     * @var list<string>
     */
    private const array TRAVERSAL_PATTERNS = [
        '/\.\.[\/\\\\]/',
        '/%2e%2e[\/\\\\%]/i',
        '/%252e%252e/i',
        '/\.\.%c0%af/i',
        '/\.\.%c1%9c/i',
    ];

    #[Override]
    public function analyze(ServerRequestInterface $request): ?ThreatEvent
    {
        /** @var mixed $rawIp */
        $rawIp = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $ip = is_string($rawIp) ? $rawIp : 'unknown';
        $values = $this->extractValues($request);

        foreach ($values as $value) {
            $lower = strtolower($value);

            foreach (self::SQL_PATTERNS as $pattern) {
                if (preg_match($pattern, $value) === 1) {
                    return ThreatEvent::create(
                        category: ThreatCategory::InjectionAttempt,
                        recommendedAction: ThreatResponse::Block,
                        sourceIp: $ip,
                        description: 'SQL injection attempt detected',
                        confidence: 0.9,
                        metadata: ['type' => 'sqli', 'pattern' => $pattern, 'path' => $request->getUri()->getPath()],
                    );
                }
            }

            foreach (self::XSS_PATTERNS as $pattern) {
                if (preg_match($pattern, $value) === 1) {
                    return ThreatEvent::create(
                        category: ThreatCategory::InjectionAttempt,
                        recommendedAction: ThreatResponse::Block,
                        sourceIp: $ip,
                        description: 'XSS attempt detected',
                        confidence: 0.85,
                        metadata: ['type' => 'xss', 'pattern' => $pattern, 'path' => $request->getUri()->getPath()],
                    );
                }
            }

            foreach (self::TRAVERSAL_PATTERNS as $pattern) {
                if (preg_match($pattern, $lower) === 1) {
                    return ThreatEvent::create(
                        category: ThreatCategory::InjectionAttempt,
                        recommendedAction: ThreatResponse::Block,
                        sourceIp: $ip,
                        description: 'Path traversal attempt detected',
                        confidence: 0.95,
                        metadata: ['type' => 'traversal', 'pattern' => $pattern, 'path' => $request->getUri()->getPath()],
                    );
                }
            }
        }

        return null;
    }

    #[Override]
    public function recordEvent(string $eventType, array $context): void
    {
        // Injection detection is stateless: no event correlation needed.
    }

    /**
     * Extract all string values from query params, parsed body, and URI path.
     *
     * @return list<string>
     */
    private function extractValues(ServerRequestInterface $request): array
    {
        $values = [];

        // URI path
        $values[] = $request->getUri()->getPath();

        // Query string
        $values[] = $request->getUri()->getQuery();

        // Query params
        $queryParams = $request->getQueryParams();
        $this->flattenValues($queryParams, $values);

        // Parsed body
        $body = $request->getParsedBody();

        if (is_array($body)) {
            $this->flattenValues($body, $values);
        }

        return $values;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param list<string>            $out
     */
    private function flattenValues(array $data, array &$out): void
    {
        /** @var mixed $value */
        foreach ($data as $value) {
            if (is_string($value)) {
                $out[] = $value;
            } elseif (is_array($value)) {
                $this->flattenValues($value, $out);
            }
        }
    }
}
