<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Fhir\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Resource\Bundle;
use Pulsar\Extension\Fhir\Resource\BundleEntry;
use Pulsar\Extension\Fhir\Resource\BundleLink;
use Pulsar\Extension\Fhir\Resource\ResourceType;

#[CoversClass(Bundle::class)]
#[CoversClass(BundleEntry::class)]
#[CoversClass(BundleLink::class)]
final class BundleTest extends TestCase
{
    public function testSearchSetBundle(): void
    {
        $bundle = new Bundle(
            type: 'searchset',
            total: 2,
            link: [
                new BundleLink('self', 'http://example.org/fhir/Patient?name=Smith'),
            ],
            entry: [
                new BundleEntry(
                    fullUrl: 'http://example.org/fhir/Patient/1',
                    resource: ['resourceType' => 'Patient', 'id' => '1', 'name' => [['family' => 'Smith']]],
                ),
                new BundleEntry(
                    fullUrl: 'http://example.org/fhir/Patient/2',
                    resource: ['resourceType' => 'Patient', 'id' => '2', 'name' => [['family' => 'Smith']]],
                ),
            ],
        );

        self::assertSame(ResourceType::Bundle, $bundle->resourceType);

        $array = $bundle->toArray();
        self::assertSame('Bundle', $array['resourceType']);
        self::assertSame('searchset', $array['type']);
        self::assertSame(2, $array['total']);
        self::assertIsArray($array['link']);
        self::assertCount(1, $array['link']);
        $link0 = $array['link'][0];
        self::assertIsArray($link0);
        self::assertSame('self', $link0['relation']);
        self::assertIsArray($array['entry']);
        self::assertCount(2, $array['entry']);
    }

    public function testBatchBundle(): void
    {
        $bundle = new Bundle(
            type: 'batch',
            entry: [
                new BundleEntry(
                    resource: ['resourceType' => 'Patient', 'name' => [['family' => 'New']]],
                    request: ['method' => 'POST', 'url' => 'Patient'],
                ),
                new BundleEntry(
                    request: ['method' => 'GET', 'url' => 'Patient/123'],
                ),
            ],
        );

        $array = $bundle->toArray();
        self::assertSame('batch', $array['type']);
        self::assertIsArray($array['entry']);
        self::assertCount(2, $array['entry']);
        $entry0 = $array['entry'][0];
        self::assertIsArray($entry0);
        $request = $entry0['request'];
        self::assertIsArray($request);
        self::assertSame('POST', $request['method']);
        self::assertNull($bundle->total);
        self::assertArrayNotHasKey('total', $array);
    }

    public function testFromArrayRoundTrip(): void
    {
        $data = [
            'resourceType' => 'Bundle',
            'type' => 'collection',
            'total' => 1,
            'link' => [
                ['relation' => 'self', 'url' => 'http://example.org/fhir/Bundle/1'],
            ],
            'entry' => [
                ['fullUrl' => 'Patient/1', 'resource' => ['resourceType' => 'Patient', 'id' => '1']],
            ],
        ];

        $bundle = Bundle::fromArray($data);
        self::assertSame('collection', $bundle->type);
        self::assertSame(1, $bundle->total);
        self::assertCount(1, $bundle->link);
        self::assertCount(1, $bundle->entry);

        $reArray = $bundle->toArray();
        self::assertSame($data['type'], $reArray['type']);
        self::assertSame($data['total'], $reArray['total']);
    }

    public function testEmptyBundle(): void
    {
        $bundle = new Bundle(type: 'searchset', total: 0);

        $array = $bundle->toArray();
        self::assertSame(0, $array['total']);
        self::assertArrayNotHasKey('entry', $array);
        self::assertArrayNotHasKey('link', $array);
    }
}
