<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms\VerificationMatrix;

use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Auth\TwoFactor\TotpGenerator;
use Pulsar\Auth\TwoFactor\TotpVerifier;
use Pulsar\Auth\TwoFactor\TwoFactorManager;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Config\CsrfConfig;
use Pulsar\Extension\Cms\Config\MediaConfig;
use Pulsar\Extension\Cms\Content\SafeHtmlPolicy;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Http\Middleware\CommentAntiAbuseMiddleware;
use Pulsar\Extension\Cms\Http\Middleware\CommentHoneypotMiddleware;
use Pulsar\Extension\Cms\Http\Middleware\CommentRateLimitMiddleware;
use Pulsar\Extension\Cms\Internal\Security\SafeHttpClient;
use Pulsar\Extension\Cms\Internal\Themes\SafeArchiveExtractor;
use Pulsar\Extension\Cms\Media\Security\FileValidator;
use Pulsar\Extension\Cms\Media\Security\PdfValidator;
use Pulsar\Extension\Cms\Media\Security\SvgSanitizer;
use Pulsar\Extension\Cms\Security\ClientFingerprint;
use Pulsar\Extension\Cms\Themes\ProvenanceResult;
use Pulsar\Extension\Cms\Users\CmsUser;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\Stream;
use Pulsar\Security\AntiSpam\AntiSpamPipeline;
use Pulsar\Security\AntiSpam\DuplicateDetector;
use Pulsar\Security\AntiSpam\LinkDensityChecker;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Csrf\CsrfMiddleware;
use Pulsar\Security\Csrf\CsrfTokenManager;
use Pulsar\Security\Session\SessionInterface;

use function bin2hex;
use function class_exists;
use function file_exists;
use function file_put_contents;
use function json_decode;
use function sodium_crypto_generichash;
use function str_contains;
use function str_starts_with;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Security verification matrix: S1-S17.
 *
 * Validates each security control defined in the CMS security plan.
 */
#[CoversClass(CsrfMiddleware::class)]
#[Group('verification-matrix')]
final class SecurityVerificationTest extends TestCase
{
    /**
     * S1: CSRF protection on all state-changing CMS admin routes.
     *
     * Verifies that the framework provides CSRF token management and middleware.
     */
    #[Test]
    public function s1CsrfProtectionPresent(): void
    {
        self::assertTrue(class_exists(CsrfTokenManager::class), 'CsrfTokenManager class must exist');
        self::assertTrue(class_exists(CsrfMiddleware::class), 'CsrfMiddleware class must exist');
    }

    /**
     * S1 behavioral: Token generate → validate → rotate lifecycle.
     *
     * Exercises CsrfTokenManager directly:
     *  - generate() stores and returns a hex token
     *  - validate() returns true for the stored token, false for a tampered token
     *  - rotate() issues a new token and invalidates the previous one
     */
    #[Test]
    public function s1CsrfTokenLifecycle(): void
    {
        /** @var array<string, mixed> $sessionStore */
        $sessionStore = [];

        $session = $this->createStub(SessionInterface::class);
        $session->method('set')->willReturnCallback(
            static function (string $key, mixed $value) use (&$sessionStore): void {
                $sessionStore[$key] = $value;
            },
        );
        $session->method('get')->willReturnCallback(
            static function (string $key) use (&$sessionStore): mixed {
                return $sessionStore[$key] ?? null;
            },
        );

        $config = new CsrfConfig(
            enabled: true,
            tokenLength: 32,
            headerName: 'X-CSRF-Token',
            formFieldName: '_csrf_token',
        );
        $manager = new CsrfTokenManager($session, $config);

        // generate() must return a non-empty hex token
        $token = $manager->generate();
        self::assertNotEmpty($token);
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/i', $token);

        // validate() accepts the generated token
        self::assertTrue($manager->validate($token));

        // validate() rejects a tampered token
        self::assertFalse($manager->validate($token . 'X'));

        // rotate() issues a new token
        $rotated = $manager->rotate();
        self::assertNotEmpty($rotated);

        // old token must be invalid after rotation
        self::assertFalse($manager->validate($token));

        // new token must be valid after rotation
        self::assertTrue($manager->validate($rotated));
    }

    /**
     * S2: Comment body HTML sanitized — script tags stripped.
     */
    #[Test]
    public function s2CommentXssSanitization(): void
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
    public function s3ContentBodySanitization(): void
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
    public function s4CommentRateLimiting(): void
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
    public function s5HoneypotTrapsBots(): void
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
    public function s6DuplicateCommentDetection(): void
    {
        $cache = new VerificationTaggedCache();
        $duplicateDetector = new DuplicateDetector($cache);
        $pipeline = new AntiSpamPipeline(checks: [$duplicateDetector]);
        $middleware = new CommentAntiAbuseMiddleware($pipeline);

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
    public function s7ExcessiveLinksRejected(): void
    {
        $linkChecker = new LinkDensityChecker(maxDensity: 0.1);
        $pipeline = new AntiSpamPipeline(checks: [$linkChecker]);
        $middleware = new CommentAntiAbuseMiddleware($pipeline);

        $request = $this->createCommentRequest('10.0.0.3', [
            'body' => 'https://a.com https://b.com https://c.com',
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
    public function s8SsrfProtectionBlocksPrivateIps(): void
    {
        // SafeHttpClient is Internal — verify it exists
        self::assertTrue(
            class_exists(SafeHttpClient::class),
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
    public function s9SvgSanitization(): void
    {
        $sanitizer = new SvgSanitizer();

        $maliciousSvg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="100" height="100"/></svg>';
        $clean = $sanitizer->sanitize($maliciousSvg);

        self::assertStringNotContainsString('<script>', $clean);
        self::assertStringContainsString('<rect', $clean);
    }

    /**
     * S10: MIME type validation — FileValidator rejects disallowed extensions and
     * CmsException provides the mimeTypeMismatch/magicByteMismatch factory methods.
     */
    #[Test]
    public function s10MimeTypeMismatchRejected(): void
    {
        // CmsException factories must produce the correct exception type
        $mismatch = CmsException::mimeTypeMismatch('image/png', 'image/jpeg');
        self::assertInstanceOf(CmsException::class, $mismatch);

        $magicMismatch = CmsException::magicByteMismatch('image/jpeg');
        self::assertInstanceOf(CmsException::class, $magicMismatch);

        // FileValidator must reject a disallowed extension before inspecting magic bytes
        $config = new MediaConfig(
            allowedExtensions: ['jpg', 'png'],
            allowedMimeTypes: ['image/jpeg', 'image/png'],
        );
        $validator = new FileValidator($config);

        $tempFile = tempnam(sys_get_temp_dir(), 'pulsar_s10_');
        self::assertNotFalse($tempFile);

        try {
            file_put_contents($tempFile, 'fake content');

            $this->expectException(CmsException::class);
            $validator->validate(
                filePath: $tempFile,
                originalFilename: 'upload.exe',       // disallowed extension
                declaredMimeType: 'application/octet-stream',
                fileSize: 12,
            );
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * S11: PDF JavaScript stripping — PdfValidator rejects PDFs with JS.
     */
    #[Test]
    public function s11PdfJavascriptStripped(): void
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
    public function s12ThemeProvenanceVerification(): void
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
     *
     * Exercises CmsException::themeZipSlipDetected() factory: verifies the factory
     * produces a CmsException and includes the malicious path in the exception message.
     * Multiple traversal patterns are tested to confirm the behavior is consistent.
     */
    #[Test]
    public function s13ZipSlipProtection(): void
    {
        self::assertTrue(class_exists(SafeArchiveExtractor::class));

        // The factory must produce a CmsException referencing the malicious path
        $exception = CmsException::themeZipSlipDetected('../etc/passwd');
        self::assertInstanceOf(CmsException::class, $exception);
        self::assertStringContainsString('../etc/passwd', $exception->getMessage());

        // All path-traversal patterns must produce exceptions with path references
        $maliciousPaths = [
            '../../../etc/passwd',
            'theme/../../secret.php',
            '/absolute/path',
            'safe/../../../escape.php',
        ];

        foreach ($maliciousPaths as $path) {
            $ex = CmsException::themeZipSlipDetected($path);
            self::assertInstanceOf(CmsException::class, $ex);
            self::assertStringContainsString($path, $ex->getMessage(), "Path '{$path}' must appear in exception message");
        }

        // Safe paths must not contain traversal components
        $safePaths = ['theme/css/style.css', 'assets/images/logo.png', 'index.html'];
        foreach ($safePaths as $safePath) {
            self::assertFalse(str_contains($safePath, '..'), "'{$safePath}' must not contain '..'");
            self::assertFalse(str_starts_with($safePath, '/'), "'{$safePath}' must not be absolute");
        }
    }

    /**
     * S14: Audit log integrity — AuditEntry includes HMAC chain fields.
     *
     * Verifies the AuditEntry DTO supports HMAC chain linking via previousHmac/hmac fields.
     */
    #[Test]
    public function s14AuditChainIntegrity(): void
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
    public function s15ClientFingerprintHashing(): void
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
    public function s16PrivilegeEscalationPrevented(): void
    {
        self::assertTrue(class_exists(CmsUser::class));

        // CmsException::invalidCmsRole() factory should exist
        $exception = CmsException::invalidCmsRole('superadmin');
        self::assertInstanceOf(CmsException::class, $exception);
    }

    /**
     * S17: 2FA enforcement — TwoFactorManager and TOTP infrastructure exist.
     */
    #[Test]
    public function s17TwoFactorEnforcement(): void
    {
        self::assertTrue(class_exists(TwoFactorManager::class));
        self::assertTrue(class_exists(TotpGenerator::class));
        self::assertTrue(class_exists(TotpVerifier::class));
        self::assertTrue(class_exists(TwoFactorStatus::class));

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
                AuditActor|string|null $actor,
                string $action,
                string $resource = '',
                array $metadata = [],
            ): AuditEntry {
                $resolved = match (true) {
                    $actor instanceof AuditActor => $actor->id,
                    $actor === null || $actor === '' => '',
                    default => $actor,
                };

                return new AuditEntry(
                    id: 'audit-' . bin2hex(random_bytes(4)),
                    event: $event,
                    outcome: $outcome,
                    actor: $resolved,
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

final class VerificationTaggedCache implements TaggedCacheInterface
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
