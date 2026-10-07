<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\I18n;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Core\Kernel;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\I18n\Locale\LocalePrefixMiddleware;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * End-to-end: boot a real Kernel with the opt-in locale-preference config and
 * drive HTTP requests through the full middleware pipeline, asserting the
 * courtesy redirect, the persistence cookie, and the Vary header — the behaviour
 * the unit tests cover in isolation, here verified through an actual boot.
 */
#[CoversClass(LocalePrefixMiddleware::class)]
final class LocalePreferenceFlowTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_locale_e2e_' . bin2hex(random_bytes(6));
        $this->configPath = $base . DIRECTORY_SEPARATOR . 'config';
        mkdir($this->configPath, 0o755, true);

        file_put_contents($this->configPath . '/app.php', '<?php return ["name" => "T", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($this->configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($this->configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');
        file_put_contents($this->configPath . '/i18n.php', '<?php return ['
            . '"default_locale" => "en", "supported_locales" => ["en", "fr", "de"], "fallback_locales" => ["en"], '
            . '"url_strategy" => "path_prefix", "courtesy_redirect" => true, "courtesy_fallback_locale" => "en", '
            . '"locale_cookie_enabled" => true];');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->configPath);
    }

    private function bootKernel(): Kernel
    {
        $kernel = new Kernel(configManager: new ConfigManager($this->configPath));
        $kernel->boot();

        return $kernel;
    }

    #[Test]
    public function courtesyRedirectsAnUnprefixedRequestToTheNegotiatedLocale(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/about')
            ->withHeader('Accept-Language', 'fr-FR,fr;q=0.9');

        $response = $this->bootKernel()->handle($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/fr/about', $response->getHeaderLine('Location'));
        self::assertStringContainsString('Cookie', $response->getHeaderLine('Vary'));
    }

    #[Test]
    public function persistsTheLocaleCookieOnAPrefixedRequest(): void
    {
        $kernel = $this->bootKernel();
        // The prefix middleware strips /fr → /widgets, so register the canonical
        // (locale-agnostic) route the router actually sees.
        $kernel->router()->get('/widgets', static fn(): Response => Response::text('ok'));

        $response = $kernel->handle(new ServerRequest(method: 'GET', uri: '/fr/widgets'));
        $cookie = $response->getHeaderLine('Set-Cookie');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('pulsar_locale=fr', $cookie);
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('SameSite=Lax', $cookie);
    }

    #[Test]
    public function defaultLocaleVisitorIsNotRedirected(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/about')
            ->withHeader('Accept-Language', 'en-US,en;q=0.9');

        $response = $this->bootKernel()->handle($request);

        // English is the default locale: its canonical URL is the unprefixed one,
        // so no courtesy redirect (and thus no loop with the canonical 301).
        self::assertNotSame(302, $response->getStatusCode());
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
