<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Waf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Security\Waf\WafAction;
use Pulsar\Security\Waf\WafConfig;
use Pulsar\Security\Waf\WafEngine;
use Pulsar\Security\Waf\WafOperator;
use Pulsar\Security\Waf\WafRule;
use Pulsar\Security\Waf\WafSeverity;
use Pulsar\Security\Waf\WafTarget;

#[CoversClass(WafEngine::class)]
final class WafEngineEdgeCasesTest extends TestCase
{
    /**
     * @param array<string, mixed> $queryParams
     * @param array<string, list<string>> $headers
     * @param array<string, string> $parsedBody
     * @param array<string, string> $cookies
     * @param array<string, string> $serverParams
     */
    private function createRequest(
        string $method = 'GET',
        string $path = '/',
        string $query = '',
        array $queryParams = [],
        array $headers = [],
        ?string $body = null,
        array $parsedBody = [],
        array $cookies = [],
        array $serverParams = [],
    ): ServerRequestInterface {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);
        $uri->method('getQuery')->willReturn($query);

        $bodyStream = $this->createStub(StreamInterface::class);
        $bodyStream->method('__toString')->willReturn($body ?? '');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn($queryParams);
        $request->method('getCookieParams')->willReturn($cookies);
        $request->method('getServerParams')->willReturn($serverParams);
        $request->method('getBody')->willReturn($bodyStream);
        $request->method('getParsedBody')->willReturn($parsedBody !== [] ? $parsedBody : null);
        $request->method('getHeaders')->willReturn($headers);
        $request->method('getHeaderLine')->willReturnCallback(
            fn(string $name) => isset($headers[$name]) ? implode(', ', $headers[$name]) : '',
        );

        return $request;
    }

    private function createEngine(int $paranoiaLevel = 1): WafEngine
    {
        return new WafEngine(new WafConfig(enabled: true, paranoiaLevel: $paranoiaLevel));
    }

    public function testContainsOperator(): void
    {
        $engine = $this->createEngine();
        $engine->loadRules([
            new WafRule('t1', 'Contains test', [WafTarget::Args], WafOperator::Contains, 'evil', WafAction::Block, WafSeverity::Critical),
        ]);

        $request = $this->createRequest(queryParams: ['x' => 'has evil in it']);
        $matches = $engine->evaluate($request);
        self::assertNotEmpty($matches);
    }

    public function testBeginsWithOperator(): void
    {
        $engine = $this->createEngine();
        $engine->loadRules([
            new WafRule('t2', 'BeginsWith test', [WafTarget::Args], WafOperator::BeginsWith, 'attack', WafAction::Log, WafSeverity::Warning),
        ]);

        $matches = $engine->evaluate($this->createRequest(queryParams: ['x' => 'attack payload here']));
        self::assertNotEmpty($matches);

        $noMatch = $engine->evaluate($this->createRequest(queryParams: ['x' => 'this is not an attack']));
        self::assertEmpty($noMatch);
    }

    public function testEndsWithOperator(): void
    {
        $engine = $this->createEngine();
        $engine->loadRules([
            new WafRule('t3', 'EndsWith test', [WafTarget::Args], WafOperator::EndsWith, '.php', WafAction::Log, WafSeverity::Notice),
        ]);

        $matches = $engine->evaluate($this->createRequest(queryParams: ['file' => 'shell.php']));
        self::assertNotEmpty($matches);

        $noMatch = $engine->evaluate($this->createRequest(queryParams: ['file' => 'image.png']));
        self::assertEmpty($noMatch);
    }

    public function testEqualsOperator(): void
    {
        $engine = $this->createEngine();
        $engine->loadRules([
            new WafRule('t4', 'Equals test', [WafTarget::Method], WafOperator::Equals, 'TRACE', WafAction::Block, WafSeverity::Critical),
        ]);

        $matches = $engine->evaluate($this->createRequest(method: 'TRACE'));
        self::assertNotEmpty($matches);

        $noMatch = $engine->evaluate($this->createRequest(method: 'GET'));
        self::assertEmpty($noMatch);
    }

    public function testRegexOperator(): void
    {
        $engine = $this->createEngine();
        $engine->loadRules([
            new WafRule('t5', 'Regex test', [WafTarget::Args], WafOperator::Regex, '/[a-z]+\d{5,}/', WafAction::Alert, WafSeverity::Warning),
        ]);

        $matches = $engine->evaluate($this->createRequest(queryParams: ['id' => 'test12345']));
        self::assertNotEmpty($matches);
    }

    public function testDetectSqliOperator(): void
    {
        $engine = $this->createEngine();
        $engine->loadRules([
            new WafRule('t6', 'SQLi test', [WafTarget::Args], WafOperator::DetectSqli, '', WafAction::Block, WafSeverity::Critical),
        ]);

        $matches = $engine->evaluate($this->createRequest(queryParams: ['id' => '1 UNION SELECT * FROM users']));
        self::assertNotEmpty($matches);
    }

    public function testDetectXssOperator(): void
    {
        $engine = $this->createEngine();
        $engine->loadRules([
            new WafRule('t7', 'XSS test', [WafTarget::Args], WafOperator::DetectXss, '', WafAction::Block, WafSeverity::Critical),
        ]);

        $matches = $engine->evaluate($this->createRequest(queryParams: ['name' => '<script>alert(1)</script>']));
        self::assertNotEmpty($matches);
    }

    public function testCookieTargetScanning(): void
    {
        $engine = $this->createEngine();
        $engine->loadRules([
            new WafRule('t8', 'Cookie test', [WafTarget::Cookies], WafOperator::DetectSqli, '', WafAction::Block, WafSeverity::Critical),
        ]);

        $matches = $engine->evaluate($this->createRequest(cookies: ['session' => '1 OR 1=1']));
        self::assertNotEmpty($matches);
    }

    public function testHeadersTargetScanning(): void
    {
        $engine = $this->createEngine();
        $engine->loadRules([
            new WafRule('t9', 'Header test', [WafTarget::Headers], WafOperator::DetectXss, '', WafAction::Block, WafSeverity::Critical),
        ]);

        $matches = $engine->evaluate($this->createRequest(headers: ['X-Custom' => ['<script>alert(1)</script>']]));
        self::assertNotEmpty($matches);
    }

    public function testUserAgentTargetScanning(): void
    {
        $engine = $this->createEngine();
        $engine->loadRules([
            new WafRule('t10', 'UA test', [WafTarget::UserAgent], WafOperator::Contains, 'sqlmap', WafAction::Block, WafSeverity::Critical),
        ]);

        $matches = $engine->evaluate($this->createRequest(headers: ['User-Agent' => ['sqlmap/1.0']]));
        self::assertNotEmpty($matches);
    }

    public function testBodyTargetWithRawBody(): void
    {
        $engine = $this->createEngine();
        $engine->loadRules([
            new WafRule('t11', 'Body test', [WafTarget::Body], WafOperator::DetectSqli, '', WafAction::Block, WafSeverity::Critical),
        ]);

        $matches = $engine->evaluate($this->createRequest(body: '1 UNION SELECT password FROM users'));
        self::assertNotEmpty($matches);
    }

    public function testBodyTargetWithEmptyBody(): void
    {
        $engine = $this->createEngine();
        $engine->loadRules([
            new WafRule('t12', 'Empty body', [WafTarget::Body], WafOperator::DetectSqli, '', WafAction::Block, WafSeverity::Critical),
        ]);

        $matches = $engine->evaluate($this->createRequest(body: ''));
        self::assertEmpty($matches);
    }

    public function testNestedQueryParamScanning(): void
    {
        $engine = $this->createEngine();
        $engine->loadRules([
            new WafRule('t13', 'Nested param', [WafTarget::Args], WafOperator::DetectSqli, '', WafAction::Block, WafSeverity::Critical),
        ]);

        $matches = $engine->evaluate($this->createRequest(queryParams: [
            'filter' => ['name' => '1 UNION SELECT * FROM users'],
        ]));
        self::assertNotEmpty($matches);
    }

    public function testParanoiaLevelFiltering(): void
    {
        $engine = $this->createEngine(paranoiaLevel: 1);
        $engine->loadRules([
            new WafRule('level1', 'Level 1', [WafTarget::Args], WafOperator::Contains, 'test1', WafAction::Log, WafSeverity::Notice, paranoiaLevel: 1),
            new WafRule('level3', 'Level 3', [WafTarget::Args], WafOperator::Contains, 'test3', WafAction::Log, WafSeverity::Notice, paranoiaLevel: 3),
        ]);

        // Level 1 engine should only match level 1 rules
        $matches = $engine->evaluate($this->createRequest(queryParams: ['x' => 'test3 payload']));
        self::assertEmpty($matches);

        $matches = $engine->evaluate($this->createRequest(queryParams: ['x' => 'test1 payload']));
        self::assertNotEmpty($matches);
    }

    #[DataProvider('sqliDetectionProvider')]
    public function testDetectSqliVariants(string $payload, bool $shouldDetect): void
    {
        $engine = $this->createEngine();
        $engine->loadRules([
            new WafRule('sqli', 'SQLi', [WafTarget::Args], WafOperator::DetectSqli, '', WafAction::Block, WafSeverity::Critical),
        ]);

        $matches = $engine->evaluate($this->createRequest(queryParams: ['q' => $payload]));

        if ($shouldDetect) {
            self::assertNotEmpty($matches, "Should detect: $payload");
        } else {
            self::assertEmpty($matches, "Should NOT detect: $payload");
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function sqliDetectionProvider(): iterable
    {
        yield 'union select' => ['1 UNION SELECT password FROM users', true];
        yield 'insert into' => ["INSERT INTO users VALUES('admin','pass')", true];
        yield 'delete from' => ['DELETE FROM users WHERE id=1', true];
        yield 'drop table' => ['DROP TABLE users', true];
        yield 'sleep injection' => ['1; SLEEP(5)', true];
        yield 'benchmark injection' => ["1; BENCHMARK(1000000, SHA1('x'))", true];
        yield 'comment injection' => ["admin'-- ", true];
        yield 'tautology' => ['1 OR 1=1', true];
        yield 'clean text' => ['Hello world this is normal', false];
    }

    #[DataProvider('xssDetectionProvider')]
    public function testDetectXssVariants(string $payload, bool $shouldDetect): void
    {
        $engine = $this->createEngine();
        $engine->loadRules([
            new WafRule('xss', 'XSS', [WafTarget::Args], WafOperator::DetectXss, '', WafAction::Block, WafSeverity::Critical),
        ]);

        $matches = $engine->evaluate($this->createRequest(queryParams: ['q' => $payload]));

        if ($shouldDetect) {
            self::assertNotEmpty($matches, "Should detect XSS: $payload");
        } else {
            self::assertEmpty($matches, "Should NOT detect XSS: $payload");
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function xssDetectionProvider(): iterable
    {
        yield 'script tag' => ['<script>alert(1)</script>', true];
        yield 'javascript uri' => ['javascript:void(0)', true];
        yield 'onerror handler' => ['<img onerror=alert(1) src=x>', true];
        yield 'iframe' => ['<iframe src="evil.com"></iframe>', true];
        yield 'object tag' => ['<object data="flash.swf">', true];
        yield 'embed tag' => ['<embed src="flash.swf">', true];
        yield 'data uri' => ['data:text/html,<h1>XSS</h1>', true];
        yield 'vbscript' => ['vbscript:msgbox("XSS")', true];
        yield 'clean text' => ['This is just normal text', false];
    }
}
