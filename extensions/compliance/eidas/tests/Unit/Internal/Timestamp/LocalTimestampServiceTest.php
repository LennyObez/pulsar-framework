<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Tests\Unit\Internal\Timestamp;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Eidas\Internal\Timestamp\LocalTimestampService;

final class LocalTimestampServiceTest extends TestCase
{
    #[Test]
    public function timestampAndVerifyRoundTrip(): void
    {
        $service = new LocalTimestampService();
        $data = 'Data to timestamp';

        $token = $service->timestamp($data);

        self::assertNotEmpty($token->tokenId);
        self::assertNotEmpty($token->dataHash);
        self::assertSame('sha256', $token->hashAlgorithm);
        self::assertSame('Pulsar Local TSA', $token->tsaName);
        self::assertFalse($token->isQualified);

        self::assertTrue($service->verifyTimestamp($data, $token));
    }

    #[Test]
    public function verifyFailsWithTamperedData(): void
    {
        $service = new LocalTimestampService();
        $token = $service->timestamp('original data');

        self::assertFalse($service->verifyTimestamp('tampered data', $token));
    }

    #[Test]
    public function timestampWithCustomHashAlgorithm(): void
    {
        $service = new LocalTimestampService();
        $data = 'Test data';

        $token = $service->timestamp($data, 'sha512');

        self::assertSame('sha512', $token->hashAlgorithm);
        self::assertTrue($service->verifyTimestamp($data, $token));
    }

    #[Test]
    public function timestampWithCustomTsaName(): void
    {
        $service = new LocalTimestampService('Custom TSA');
        $token = $service->timestamp('data');

        self::assertSame('Custom TSA', $token->tsaName);
    }

    #[Test]
    public function eachTimestampHasUniqueTokenId(): void
    {
        $service = new LocalTimestampService();
        $t1 = $service->timestamp('data');
        $t2 = $service->timestamp('data');

        self::assertNotSame($t1->tokenId, $t2->tokenId);
    }
}
