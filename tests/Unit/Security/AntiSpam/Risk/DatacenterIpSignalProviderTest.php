<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\Risk;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\TrustedProxy;
use Pulsar\Security\AntiSpam\Risk\DatacenterIpConfig;
use Pulsar\Security\AntiSpam\Risk\DatacenterIpSignalProvider;

#[CoversClass(DatacenterIpSignalProvider::class)]
final class DatacenterIpSignalProviderTest extends TestCase
{
    #[Test]
    public function scoresWhenClientIpIsInADatacenterRange(): void
    {
        $provider = new DatacenterIpSignalProvider(
            new DatacenterIpConfig(enabled: true, ranges: ['203.0.113.0/24'], score: 0.5),
        );

        $signal = $provider->evaluate($this->request('203.0.113.7'));

        self::assertSame(0.5, $signal->score);
        self::assertSame('datacenter', $signal->source);
    }

    #[Test]
    public function scoresZeroWhenIpIsOutsideRanges(): void
    {
        $provider = new DatacenterIpSignalProvider(
            new DatacenterIpConfig(enabled: true, ranges: ['203.0.113.0/24'], score: 0.5),
        );

        self::assertSame(0.0, $provider->evaluate($this->request('8.8.8.8'))->score);
    }

    #[Test]
    public function scoresZeroWhenDisabledOrNoRanges(): void
    {
        $disabled = new DatacenterIpSignalProvider(new DatacenterIpConfig(enabled: false, ranges: ['203.0.113.0/24']));
        self::assertSame(0.0, $disabled->evaluate($this->request('203.0.113.7'))->score);

        $noRanges = new DatacenterIpSignalProvider(new DatacenterIpConfig(enabled: true, ranges: []));
        self::assertSame(0.0, $noRanges->evaluate($this->request('203.0.113.7'))->score);
    }

    #[Test]
    public function resolvesClientIpThroughTrustedProxy(): void
    {
        // Real client (in the datacenter range) is in X-Forwarded-For; REMOTE_ADDR
        // is the trusted proxy. The signal must score the forwarded client.
        $provider = new DatacenterIpSignalProvider(
            new DatacenterIpConfig(enabled: true, ranges: ['203.0.113.0/24'], score: 0.5),
            new TrustedProxy(['10.0.0.0/8']),
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['X-Forwarded-For' => '203.0.113.7'],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        self::assertSame(0.5, $provider->evaluate($request)->score);
    }

    private function request(string $remoteAddr): ServerRequest
    {
        return new ServerRequest(method: 'GET', uri: '/', serverParams: ['REMOTE_ADDR' => $remoteAddr]);
    }
}
