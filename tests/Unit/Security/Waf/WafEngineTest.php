<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Waf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Security\Waf\OwaspCoreRuleSet;
use Pulsar\Security\Waf\WafAction;
use Pulsar\Security\Waf\WafConfig;
use Pulsar\Security\Waf\WafEngine;
use Pulsar\Security\Waf\WafOperator;
use Pulsar\Security\Waf\WafRule;
use Pulsar\Security\Waf\WafRuleMatch;
use Pulsar\Security\Waf\WafSeverity;
use Pulsar\Security\Waf\WafTarget;

use function count;

#[CoversClass(WafEngine::class)]
#[CoversClass(WafConfig::class)]
#[CoversClass(WafRule::class)]
#[CoversClass(WafRuleMatch::class)]
#[CoversClass(OwaspCoreRuleSet::class)]
final class WafEngineTest extends TestCase
{
    private function createEngine(int $paranoiaLevel = 1, bool $enabled = true): WafEngine
    {
        $config = new WafConfig(enabled: $enabled, paranoiaLevel: $paranoiaLevel);
        $engine = new WafEngine($config);
        $engine->loadRules(OwaspCoreRuleSet::rules());

        return $engine;
    }

    /**
     * @param array<string, string> $queryParams
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

    public function testCleanRequestPassesThrough(): void
    {
        $engine = $this->createEngine();
        $request = $this->createRequest(queryParams: ['search' => 'hello world']);

        $matches = $engine->evaluate($request);
        self::assertSame([], $matches);
    }

    public function testDisabledEngineSkipsEvaluation(): void
    {
        $engine = $this->createEngine(enabled: false);
        $request = $this->createRequest(queryParams: ['id' => "1' OR '1'='1"]);

        $matches = $engine->evaluate($request);
        self::assertSame([], $matches);
    }

    public function testBypassIpSkipsEvaluation(): void
    {
        $config = new WafConfig(enabled: true, bypassIps: ['10.0.0.1']);
        $engine = new WafEngine($config);
        $engine->loadRules(OwaspCoreRuleSet::rules());

        $request = $this->createRequest(
            queryParams: ['id' => '1 UNION SELECT * FROM users'],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        $matches = $engine->evaluate($request);
        self::assertSame([], $matches);
    }

    #[DataProvider('sqliPayloads')]
    public function testDetectsSqlInjection(string $payload): void
    {
        $engine = $this->createEngine();
        $request = $this->createRequest(queryParams: ['id' => $payload]);

        $matches = $engine->evaluate($request);
        self::assertNotEmpty($matches, "SQLi payload not detected: $payload");
        self::assertTrue($engine->shouldBlock($matches));
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function sqliPayloads(): iterable
    {
        yield 'union select' => ['1 UNION SELECT username, password FROM users'];
        yield 'tautology' => ['1 OR 1=1'];
        yield 'sleep blind' => ['1; SLEEP(5)'];
        yield 'benchmark blind' => ["1; BENCHMARK(10000000, SHA1('test'))"];
    }

    #[DataProvider('xssPayloads')]
    public function testDetectsXss(string $payload): void
    {
        $engine = $this->createEngine();
        $request = $this->createRequest(queryParams: ['name' => $payload]);

        $matches = $engine->evaluate($request);
        self::assertNotEmpty($matches, "XSS payload not detected: $payload");
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function xssPayloads(): iterable
    {
        yield 'script tag' => ['<script>alert(1)</script>'];
        yield 'javascript uri' => ['javascript:alert(1)'];
        yield 'event handler' => ['<img onerror=alert(1) src=x>'];
        yield 'iframe' => ['<iframe src="evil.com"></iframe>'];
    }

    #[DataProvider('pathTraversalPayloads')]
    public function testDetectsPathTraversal(string $path): void
    {
        $engine = $this->createEngine();
        $request = $this->createRequest(path: $path);

        $matches = $engine->evaluate($request);
        self::assertNotEmpty($matches, "Path traversal not detected: $path");
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function pathTraversalPayloads(): iterable
    {
        yield 'dotdot slash' => ['/../../../etc/passwd'];
        yield 'encoded' => ['/%2e%2e/%2e%2e/etc/passwd'];
        yield 'etc passwd direct' => ['/etc/passwd'];
    }

    #[DataProvider('rfiPayloads')]
    public function testDetectsRfi(string $value): void
    {
        $engine = $this->createEngine();
        $request = $this->createRequest(queryParams: ['page' => $value]);

        $matches = $engine->evaluate($request);
        self::assertNotEmpty($matches, "RFI payload not detected: $value");
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function rfiPayloads(): iterable
    {
        yield 'http url' => ['http://evil.com/shell.php'];
        yield 'php wrapper' => ['php://filter/convert.base64-encode/resource=index.php'];
        yield 'data wrapper' => ['data://text/plain,<?php system("id")?>'];
    }

    /** @param array<string, string> $queryParams */
    #[DataProvider('legitimateRequests')]
    public function testLegitimateRequestsNotBlocked(array $queryParams, string $path = '/'): void
    {
        $engine = $this->createEngine();
        $request = $this->createRequest(queryParams: $queryParams, path: $path);

        $matches = $engine->evaluate($request);
        $blocked = $engine->shouldBlock($matches);
        self::assertFalse($blocked, 'Legitimate request was blocked');
    }

    /**
     * @return iterable<string, array{0: array<string, string>, 1?: string}>
     */
    public static function legitimateRequests(): iterable
    {
        yield 'simple search' => [['q' => 'best PHP framework']];
        yield 'pagination' => [['page' => '2', 'sort' => 'name']];
        yield 'normal text' => [['comment' => 'I love this product!']];
        yield 'normal path' => [[], '/docs/1.0/routing'];
        yield 'email in query' => [['email' => 'user@example.com']];
    }

    public function testParanoiaLevelFiltersRules(): void
    {
        $engine1 = $this->createEngine(paranoiaLevel: 1);
        $engine4 = $this->createEngine(paranoiaLevel: 4);

        $request = $this->createRequest(queryParams: ['x' => '`ls`']);

        $matches1 = $engine1->evaluate($request);
        $matches4 = $engine4->evaluate($request);

        // Higher paranoia should catch more
        self::assertGreaterThanOrEqual(count($matches1), count($matches4));
    }

    public function testShouldBlockReturnsFalseForLogOnly(): void
    {
        $config = new WafConfig(enabled: true);
        $engine = new WafEngine($config);
        $engine->loadRules([
            new WafRule(
                id: 'test-1',
                message: 'Test log rule',
                targets: [WafTarget::Args],
                operator: WafOperator::Contains,
                pattern: 'flagged',
                action: WafAction::Log,
                severity: WafSeverity::Notice,
            ),
        ]);

        $request = $this->createRequest(queryParams: ['x' => 'flagged value']);
        $matches = $engine->evaluate($request);

        self::assertNotEmpty($matches);
        self::assertFalse($engine->shouldBlock($matches));
    }

    public function testWafConfigFromArray(): void
    {
        $config = WafConfig::fromArray([
            'enabled' => true,
            'paranoia_level' => 3,
            'bypass_ips' => ['192.168.1.1'],
            'custom_rules_path' => '/etc/waf/rules.php',
        ]);

        self::assertTrue($config->enabled);
        self::assertSame(3, $config->paranoiaLevel);
        self::assertSame(['192.168.1.1'], $config->bypassIps);
        self::assertSame('/etc/waf/rules.php', $config->customRulesPath);
    }

    public function testWafConfigClampsParanoiaLevel(): void
    {
        $config = WafConfig::fromArray(['paranoia_level' => 10]);
        self::assertSame(4, $config->paranoiaLevel);

        $config2 = WafConfig::fromArray(['paranoia_level' => 0]);
        self::assertSame(1, $config2->paranoiaLevel);
    }

    public function testDetectsSqlInjectionInBody(): void
    {
        $engine = $this->createEngine();
        $request = $this->createRequest(
            method: 'POST',
            parsedBody: ['username' => "admin' OR '1'='1"],
        );

        $matches = $engine->evaluate($request);
        self::assertNotEmpty($matches);
    }

    public function testOwaspCoreRuleSetReturnsRules(): void
    {
        $rules = OwaspCoreRuleSet::rules();
        self::assertNotEmpty($rules);

        // Verify each rule has required fields
        foreach ($rules as $rule) {
            self::assertNotEmpty($rule->id);
            self::assertNotEmpty($rule->message);
            self::assertNotEmpty($rule->targets);
            self::assertGreaterThanOrEqual(1, $rule->paranoiaLevel);
            self::assertLessThanOrEqual(4, $rule->paranoiaLevel);
        }
    }
}
