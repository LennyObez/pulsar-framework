<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Tests\Unit\Internal\Seal;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Eidas\Exception\EidasException;
use Pulsar\Extension\Eidas\Internal\Seal\HmacSealService;

final class HmacSealServiceTest extends TestCase
{
    #[Test]
    public function sealAndVerifyRoundTrip(): void
    {
        $service = new HmacSealService([
            'org_key_001' => ['key' => 'org_secret', 'name' => 'Acme Corp', 'id' => 'ORG-001'],
        ]);

        $data = 'Document to seal';
        $seal = $service->seal($data, 'org_key_001');
        $info = $service->verifySeal($data, $seal);

        self::assertTrue($info->valid);
        self::assertSame('Acme Corp', $info->organizationName);
        self::assertSame('ORG-001', $info->organizationId);
    }

    #[Test]
    public function sealWithUnknownKeyThrows(): void
    {
        $service = new HmacSealService([]);

        $this->expectException(EidasException::class);
        $this->expectExceptionMessageIsOrContains('Unknown seal key');

        $service->seal('data', 'nonexistent');
    }

    #[Test]
    public function verifyTamperedDataReturnsFalse(): void
    {
        $service = new HmacSealService([
            'org_key_001' => ['key' => 'secret', 'name' => 'Org', 'id' => 'O1'],
        ]);

        $seal = $service->seal('original', 'org_key_001');
        $info = $service->verifySeal('tampered', $seal);

        self::assertFalse($info->valid);
        self::assertSame('MAC mismatch', $info->reason);
    }

    #[Test]
    public function verifyWithUnknownKeyReturnsFalse(): void
    {
        $service = new HmacSealService([
            'org_key_001' => ['key' => 'secret', 'name' => 'Org', 'id' => 'O1'],
        ]);
        $seal = $service->seal('data', 'org_key_001');

        $otherService = new HmacSealService([]);
        $info = $otherService->verifySeal('data', $seal);

        self::assertFalse($info->valid);
        self::assertSame('Unknown seal key', $info->reason);
    }
}
