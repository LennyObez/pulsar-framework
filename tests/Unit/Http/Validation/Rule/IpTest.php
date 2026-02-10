<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Ip;

#[CoversClass(Ip::class)]
final class IpTest extends TestCase
{
    private Ip $rule;

    protected function setUp(): void
    {
        $this->rule = new Ip();
    }

    #[Test]
    public function validIpv4Passes(): void
    {
        self::assertNull($this->rule->validate('field', '192.168.1.1', []));
    }

    #[Test]
    public function validIpv6Passes(): void
    {
        self::assertNull($this->rule->validate('field', '::1', []));
    }

    #[Test]
    public function invalidIpFails(): void
    {
        $violation = $this->rule->validate('field', '999.999.999.999', []);
        self::assertNotNull($violation);
        self::assertSame('ip', $violation->rule);
    }

    #[Test]
    public function v4OnlyRejectsIpv6(): void
    {
        $rule = new Ip(version: 'v4');
        $violation = $rule->validate('field', '::1', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function v4OnlyAcceptsIpv4(): void
    {
        $rule = new Ip(version: 'v4');
        self::assertNull($rule->validate('field', '10.0.0.1', []));
    }

    #[Test]
    public function v6OnlyRejectsIpv4(): void
    {
        $rule = new Ip(version: 'v6');
        $violation = $rule->validate('field', '192.168.1.1', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function v6OnlyAcceptsIpv6(): void
    {
        $rule = new Ip(version: 'v6');
        self::assertNull($rule->validate('field', '2001:0db8:85a3::8a2e:0370:7334', []));
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('field', null, []));
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new Ip(message: 'Bad IP');
        $violation = $rule->validate('field', 'invalid', []);
        self::assertNotNull($violation);
        self::assertSame('Bad IP', $violation->message);
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $violation = $this->rule->validate('field', '', []);
        self::assertNotNull($violation);
    }
}
