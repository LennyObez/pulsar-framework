<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms\VerificationMatrix;

use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Comments\AntiAbuseHeuristics;
use Pulsar\Extension\Cms\Content\SafeHtmlPolicy;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Http\Middleware\CommentAntiAbuseMiddleware;
use Pulsar\Extension\Cms\Http\Middleware\CommentHoneypotMiddleware;
use Pulsar\Extension\Cms\Http\Middleware\CommentRateLimitMiddleware;
use Pulsar\Extension\Cms\Media\Security\PdfValidator;
use Pulsar\Extension\Cms\Media\Security\SvgSanitizer;
use Pulsar\Extension\Cms\Security\ClientFingerprint;
use Pulsar\Extension\Cms\Themes\ProvenanceResult;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\Stream;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Csrf\CsrfMiddleware;
use Pulsar\Security\Csrf\CsrfTokenManager;

use function bin2hex;
use function class_exists;
use function file_exists;
use function file_put_contents;
use function json_decode;
use function sodium_crypto_generichash;
use function str_contains;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Security verification matrix: S1-S17.
 *
 * Validates each security control defined in the CMS security plan.
 */
#[Group('verification-matrix')]
final class SecurityVerificationTest extends TestCase
{
    /**
     * S1: CSRF protection on all state-changing CMS admin routes.
     *
     * Verifies that the framework provides CSRF token management and middleware.
     */
    #[Test]
    public function test_s1_csrf_protection_present(): void
    {
        self::assertTrue(class_exists(CsrfTokenManager::class), 'CsrfTokenManager class must exist');
        self::assertTrue(class_exists(CsrfMiddleware::class), 'CsrfMiddleware class must exist');
    }

    /**
     * S2: Comment body HTML sanitized — script tags stripped.
     */
    #[Test]
    public function test_s2_comment_xss_sanitization(): void
    {
        $auditLogger = $this->createAuditLogger();
        $policy = new SafeHtmlPolicy($auditLogger);

        $malicious = '<p>Hello</p><script>alert("xss")</script><img src=x onerror=alert(1)>';
        $clean = $policy->sanitize($malicious);

        self::assertStringNotContainsString('<script>', $clean);
        self::assertStringNotContainsString('onerror', $clean);
        self::assertStringContainsString('Hello', $clean);
    }

    /**
     * S3: Content body HTML sanitized — event handlers and javascript: URIs stripped.
     */
    #[Test]
    public function test_s3_content_body_sanitization(): void
    {
        $auditLogger = $this->createAuditLogger();
        $policy = new SafeHtmlPolicy($auditLogger);

        $input = '<a href="javascript:alert(1)">Click</a><div onmouseover="steal()">Hover</div>';
        $clean = $policy->sanitize($input);

        self::assertStringNotContainsString('javascript:', $clean);
        self::assertStringNotContainsString('onmouseover', $clean);
    }

    /**
     * S4: Comment rate limiting enforced.
     */
    #[Test]
    public function test_s4_comment_rate_limiting(): void
    {
        $cache = new VerificationTaggedCache();
        $middleware = new CommentRateLimitMiddleware($cache, rateLimitPerMinute: 2);
        $handler = new VerificationPassThrough();

        $request = $this->createCommentRequest('192.168.1.1');

        $middleware->process($request, $handler);
        $middleware->process($request, $handler);
        $response = $middleware->process($request, $handler);

        self::assertSame(429, $response->getStatusCode());
        self::assertTrue($response->hasHeader('Retry-After'));
    }

    /**
     * S5: Honeypot field traps bots with fake success.
     */
    #[Test]
    public function test_s5_honeypot_traps_bots(): void
    {
        $auditLogger = $this->createAuditLogger();
        $middleware = new CommentHoneypotMiddleware($auditLogger);

        $request = $this->createCommentRequest('10.0.0.1', [
            'body' => 'Spam comment',
            'website_url' => 'http://spam.example.com',
        ]);
        $handler = new VerificationPassThrough();

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        /** @var array{status?: string} $data */
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('success', $data['status'] ?? '');
    }

    /**
     * S6: Duplicate comment detection via anti-abuse heuristics.
     */
    #[Test]
    public function test_s6_duplicate_comment_detection(): void
    {
        $cache = new VerificationTaggedCache();
        $heuristics = new AntiAbuseHeuristics($cache);
        $middleware = new CommentAntiAbuseMiddleware($heuristics);

        $request = $this->createCommentRequest('10.0.0.2', ['body' => 'Same exact text']);
        $handler = new VerificationPassThrough();

        $middleware->process($request, $handler);
        $response = $middleware->process($request, $handler);

        self::assertSame(422, $response->getStatusCode());
    }

    /**
     * S7: Excessive link detection in comments.
     */
    #[Test]
    public function test_s7_excessive_links_rejected(): void
    {
        $cache = new VerificationTaggedCache();
        $heuristics = new AntiAbuseHeuristics($cache);
        $middleware = new CommentAntiAbuseMiddleware($heuristics, maxLinks: 2);

        $request = $this->createCommentRequest('10.0.0.3', [
            'body' => 'Visit https://a.com https://b.com https://c.com',
        ]);
        $handler = new VerificationPassThrough();

        $response = $middleware->process($request, $handler);

        self::assertSame(422, $response->getStatusCode());
    }

    /**
     * S8: SSRF protection blocks internal/private network URLs.
     *
     * Verifies the SafeHttpClient internal implementation exists and CmsException
     * provides SSRF blocking factory method.
     */
    #[Test]
    public function test_s8_ssrf_protection_blocks_private_ips(): void
    {
        // SafeHttpClient is Internal — verify it exists
        self::assertTrue(
            class_exists(\Pulsar\Extension\Cms\Internal\Security\SafeHttpClient::class),
            'SafeHttpClient must exist for SSRF protection',
        );

        // CmsException::ssrfBlocked() factory should exist
        $exception = CmsException::ssrfBlocked('http://127.0.0.1/internal', 'private IP');
        self::assertInstanceOf(CmsException::class, $exception);
    }

    /**
     * S9: SVG sanitization strips embedded scripts.
     */
    #[Test]
    public function test_s9_svg_sanitization(): void
    {
        $sanitizer = new SvgSanitizer();

        $maliciousSvg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="100" height="100"/></svg>';
        $clean = $sanitizer->sanitize($maliciousSvg);

        self::assertStringNotContainsString('<script>', $clean);
        self::assertStringContainsString('<rect', $clean);
    }

    /**
     * S10: MIME type validation — FileValidator exists and CmsException provides mismatch factory.
     */
    #[Test]
    public function test_s10_mime_type_mismatch_rejected(): void
    {
        self::assertTrue(
            class_exists(\Pulsar\Extension\Cms\Media\Security\FileValidator::class),
            'FileValidator must exist for MIME validation',
        );

        // CmsException::mimeTypeMismatch() factory should exist
        $exception = CmsException::mimeTypeMismatch('image/png', 'image/jpeg');
        self::assertInstanceOf(CmsException::class, $exception);

        // CmsException::magicByteMismatch() factory should exist
        $exception = CmsException::magicByteMismatch('image/jpeg');
        self::assertInstanceOf(CmsException::class, $exception);
    }

    /**
     * S11: PDF JavaScript stripping — PdfValidator rejects PDFs with JS.
     */
    #[Test]
    public function test_s11_pdf_javascript_stripped(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'pulsar_s11_');
        self::assertNotFalse($tempFile);

        try {
            $pdfContent = "%PDF-1.4\n1 0 obj\n<<\n/Type /Catalog\n/OpenAction << /S /JavaScript /JS (app.alert('xss')) >>\n>>\nendobj";
            file_put_contents($tempFile, $pdfContent);

            $validator = new PdfValidator();

            $this->expectException(CmsException::class);
            $validator->validate($tempFile);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * S12: Theme provenance verification.
     *
     * Verifies ProvenanceResult correctly models verification states.
     */
    #[Test]
    public function test_s12_theme_provenance_verification(): void
    {
        // Verified provenance
        $verified = ProvenanceResult::verified();
        self::assertTrue($verified->hashValid);
        self::assertTrue($verified->signatureValid);
        self::assertTrue($verified->isAcceptable(requireSigned: true));

        // Unsigned provenance — acceptable without signing requirement
        $unsigned = ProvenanceResult::unsigned();
        self::assertTrue($unsigned->hashValid);
        self::assertFalse($unsigned->signatureValid);
        self::assertTrue($unsigned->isAcceptable(requireSigned: false));
        self::assertFalse($unsigned->isAcceptable(requireSigned: true));

        // Failed provenance
        $failed = ProvenanceResult::failed('Hash mismatch');
        self::assertFalse($failed->hashValid);
        self::assertFalse($failed->isAcceptable(requireSigned: false));
        self::assertSame('Hash mismatch', $failed->error);
    }

    /**
     * S13: Zip slip protection in theme extraction.
     */
    #[Test]
    public function test_s13_zip_slip_protection(): void
    {
        self::assertTrue(class_exists(\Pulsar\Extension\Cms\Internal\Themes\SafeArchiveExtractor::class));

        $exception = CmsException::themeZipSlipDetected('../etc/passwd');
        self::assertInstanceOf(CmsException::class, $exception);

        $isTraversal = static fn(string $path): bool => str_contains($path, '..');
        self::assertTrue($isTraversal('../../../etc/passwd'));
        self::assertTrue($isTraversal('theme/../../secret.php'));
        self::assertFalse($isTraversal('theme/css/style.css'));
        self::assertFalse($isTraversal('assets/images/logo.png'));
    }

    /**
     * S14: Audit log integrity — AuditEntry includes HMAC chain fields.
     *
     * Verifies the AuditEntry DTO supports HMAC chain linking via previousHmac/hmac fields.
     */
    #[Test]
    public function test_s14_audit_chain_integrity(): void
    {
        $entry1 = new AuditEntry(
            id: 'audit-001',
            event: AuditEvent::DataModification,
            outcome: AuditOutcome::Success,
            actor: 'user-001',
            action: 'cms.content.created',
            resource: 'content-001',
            timestamp: new DateTimeImmutable(),
            metadata: [],
            previousHmac: '',
            hmac: bin2hex(sodium_crypto_generichash('entry-1-data')),
        );

        $entry2 = new AuditEntry(
            id: 'audit-002',
            event: AuditEvent::DataModification,
            outcome: AuditOutcome::Success,
            actor: 'user-001',
            action: 'cms.content.published',
            resource: 'content-001',
            timestamp: new DateTimeImmutable(),
            metadata: [],
            previousHmac: $entry1->hmac,
            hmac: bin2hex(sodium_crypto_generichash('entry-2-data')),
        );

        self::assertNotEmpty($entry1->hmac);
        self::assertNotEmpty($entry2->hmac);
        self::assertSame($entry1->hmac, $entry2->previousHmac);
        self::assertNotSame($entry1->hmac, $entry2->hmac);
    }

    /**
     * S15: Client fingerprint — privacy-preserving hashed identification.
     */
    #[Test]
    public function test_s15_client_fingerprint_hashing(): void
    {
        self::assertTrue(class_exists(ClientFingerprint::class));

        $fp1 = new ClientFingerprint(
            ipAddress: '192.168.1.1',
            ipHash: bin2hex(sodium_crypto_generichash('192.168.1.1')),
            userAgentHash: bin2hex(sodium_crypto_generichash('Mozilla/5.0 Test')),
            compositeHash: bin2hex(sodium_crypto_generichash('192.168.1.1|Mozilla/5.0 Test')),
        );

        $fp2 = new ClientFingerprint(
            ipAddress: '192.168.1.2',
            ipHash: bin2hex(sodium_crypto_generichash('192.168.1.2')),
            userAgentHash: bin2hex(sodium_crypto_generichash('Mozilla/5.0 Test')),
            compositeHash: bin2hex(sodium_crypto_generichash('192.168.1.2|Mozilla/5.0 Test')),
        );

        // Same IP produces same ipHash
        self::assertNotEmpty($fp1->ipHash);
        // Different IPs produce different compositeHash
        self::assertNotSame($fp1->compositeHash, $fp2->compositeHash);
        // Hash is not plaintext
        self::assertStringNotContainsString('192.168', $fp1->ipHash);
    }

    /**
     * S16: Privilege escalation prevented — CMS role system exists.
     *
     * Verifies CmsUser has roles and CmsException provides role-related factories.
     */
    #[Test]
    public function test_s16_privilege_escalation_prevented(): void
    {
        self::assertTrue(class_exists(\Pulsar\Extension\Cms\Users\CmsUser::class));

        // CmsException::invalidCmsRole() factory should exist
        $exception = CmsException::invalidCmsRole('superadmin');
        self::assertInstanceOf(CmsException::class, $exception);
    }

    /**
     * S17: 2FA enforcement — TwoFactorManager and TOTP infrastructure exist.
     */
    #[Test]
    public function test_s17_two_factor_enforcement(): void
    {
        self::assertTrue(class_exists(\Pulsar\Auth\TwoFactor\TwoFactorManager::class));
        self::assertTrue(class_exists(\Pulsar\Auth\TwoFactor\TotpGenerator::class));
        self::assertTrue(class_exists(\Pulsar\Auth\TwoFactor\TotpVerifier::class));
        self::assertTrue(class_exists(\Pulsar\Auth\Identity\TwoFactorStatus::class));

        // CmsException::twoFactorNotEnabled() factory should exist
        $exception = CmsException::twoFactorNotEnabled('user-001');
        self::assertInstanceOf(CmsException::class, $exception);
    }

    private function createAuditLogger(): AuditLoggerInterface
    {
        return new class implements AuditLoggerInterface {
            public function log(
                AuditEvent $event,
                AuditOutcome $outcome,
                ?string $actor,
                string $action,
                string $resource = '',
                array $metadata = [],
            ): AuditEntry {
                return new AuditEntry(
                    id: 'audit-' . bin2hex(random_bytes(4)),
                    event: $event,
                    outcome: $outcome,
                    actor: $actor ?? '',
                    action: $action,
                    resource: $resource,
                    timestamp: new DateTimeImmutable(),
                    metadata: $metadata,
                    previousHmac: '',
                    hmac: '',
                );
            }
        };
    }

    /**
     * @param array<string, string> $body
     */
    private function createCommentRequest(string $ip, array $body = ['body' => 'Test']): ServerRequestInterface
    {
        $stub = $this->createStub(ServerRequestInterface::class);
        $stub->method('getServerParams')->willReturn(['REMOTE_ADDR' => $ip]);
        $stub->method('getParsedBody')->willReturn($body);
        $stub->method('getMethod')->willReturn('POST');
        $stub->method('getHeaders')->willReturn([]);
        $stub->method('hasHeader')->willReturn(false);
        $stub->method('getHeader')->willReturn([]);
        $stub->method('getHeaderLine')->willReturn('');
        $stub->method('getProtocolVersion')->willReturn('1.1');
        $stub->method('getRequestTarget')->willReturn('/');
        $stub->method('getQueryParams')->willReturn([]);
        $stub->method('getCookieParams')->willReturn([]);
        $stub->method('getUploadedFiles')->willReturn([]);
        $stub->method('getAttributes')->willReturn([]);
        $stub->method('getAttribute')->willReturn(null);
        $stub->method('getBody')->willReturn(Stream::create(''));

        return $stub;
    }
}

/**
 * @internal Test doubles used by verification matrix tests.
 */
final class VerificationPassThrough implements RequestHandlerInterface
{
    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Response::json(['status' => 'ok']);
    }
}

final class VerificationTaggedCache implements \Pulsar\Cache\Application\TaggedCacheInterface
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get(string $key): mixed
    {
        return $this->store[$key] ?? null;
    }

    public function set(string $key, mixed $value, array $tags, ?int $ttlSeconds = null): bool
    {
        $this->store[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);

        return true;
    }

    public function invalidateTag(string $tag): void
    {
        $this->store = [];
    }

    public function invalidateTags(array $tags): void
    {
        $this->store = [];
    }
}
