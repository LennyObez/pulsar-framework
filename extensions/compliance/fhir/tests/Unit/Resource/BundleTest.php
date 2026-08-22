<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Resource\Bundle;
use Pulsar\Extension\Fhir\Resource\BundleEntry;
use Pulsar\Extension\Fhir\Resource\BundleLink;

#[CoversClass(Bundle::class)]
#[CoversClass(BundleEntry::class)]
#[CoversClass(BundleLink::class)]
final class BundleTest extends TestCase
{
    #[Test]
    public function minimalBundleToArray(): void
    {
        $bundle = new Bundle();

        $array = $bundle->toArray();

        self::assertSame('Bundle', $array['resourceType']);
        self::assertArrayNotHasKey('type', $array);
        self::assertArrayNotHasKey('total', $array);
        self::assertArrayNotHasKey('link', $array);
        self::assertArrayNotHasKey('entry', $array);
        self::assertArrayNotHasKey('timestamp', $array);
    }

    #[Test]
    public function searchsetBundleToArray(): void
    {
        $bundle = new Bundle(
            id: 'bundle-1',
            type: 'searchset',
            total: 42,
            link: [
                new BundleLink(relation: 'self', url: 'https://fhir.example.com/Patient?name=smith'),
                new BundleLink(relation: 'next', url: 'https://fhir.example.com/Patient?name=smith&_page=2'),
            ],
            entry: [
                new BundleEntry(
                    fullUrl: 'https://fhir.example.com/Patient/123',
                    resource: ['resourceType' => 'Patient', 'id' => '123'],
                ),
            ],
            timestamp: '2026-03-15T10:00:00Z',
        );

        $array = $bundle->toArray();

        self::assertSame('bundle-1', $array['id']);
        self::assertSame('searchset', $array['type']);
        self::assertSame(42, $array['total']);
        self::assertSame('2026-03-15T10:00:00Z', $array['timestamp']);
        self::assertCount(2, $array['link']);
        self::assertSame('self', $array['link'][0]['relation']);
        self::assertSame('next', $array['link'][1]['relation']);
        self::assertCount(1, $array['entry']);
        self::assertSame('https://fhir.example.com/Patient/123', $array['entry'][0]['fullUrl']);
        self::assertSame('Patient', $array['entry'][0]['resource']['resourceType']);
    }

    #[Test]
    public function fromArrayRoundTrip(): void
    {
        $data = [
            'resourceType' => 'Bundle',
            'id' => 'b-42',
            'type' => 'collection',
            'total' => 2,
            'link' => [
                ['relation' => 'self', 'url' => 'https://example.com/fhir/Bundle/b-42'],
            ],
            'entry' => [
                ['fullUrl' => 'Patient/1', 'resource' => ['resourceType' => 'Patient', 'id' => '1']],
                ['fullUrl' => 'Patient/2', 'resource' => ['resourceType' => 'Patient', 'id' => '2']],
            ],
            'timestamp' => '2026-01-01T00:00:00Z',
        ];

        $bundle = Bundle::fromArray($data);

        self::assertSame('b-42', $bundle->id);
        self::assertSame('collection', $bundle->type);
        self::assertSame(2, $bundle->total);
        self::assertSame('2026-01-01T00:00:00Z', $bundle->timestamp);
        self::assertCount(1, $bundle->link);
        self::assertSame('self', $bundle->link[0]->relation);
        self::assertCount(2, $bundle->entry);
        self::assertSame('Patient/1', $bundle->entry[0]->fullUrl);
        self::assertSame('Patient/2', $bundle->entry[1]->fullUrl);
    }

    #[Test]
    public function fromArrayEmptyBundle(): void
    {
        $bundle = Bundle::fromArray([]);

        self::assertNull($bundle->id);
        self::assertNull($bundle->type);
        self::assertNull($bundle->total);
        self::assertSame([], $bundle->link);
        self::assertSame([], $bundle->entry);
    }

    #[Test]
    public function fromArrayIgnoresNonStringTypeValues(): void
    {
        $bundle = Bundle::fromArray([
            'id' => 123,
            'type' => false,
            'total' => 'not-a-number',
            'timestamp' => ['array'],
        ]);

        self::assertNull($bundle->id);
        self::assertNull($bundle->type);
        self::assertNull($bundle->total);
        self::assertNull($bundle->timestamp);
    }

    #[Test]
    public function fromArrayParsesNumericTotal(): void
    {
        $bundle = Bundle::fromArray(['total' => '15']);

        self::assertSame(15, $bundle->total);
    }

    // --- BundleEntry ---

    #[Test]
    public function bundleEntryToArrayMinimal(): void
    {
        $entry = new BundleEntry();

        self::assertSame([], $entry->toArray());
    }

    #[Test]
    public function bundleEntryToArrayFull(): void
    {
        $entry = new BundleEntry(
            fullUrl: 'Patient/1',
            resource: ['resourceType' => 'Patient', 'id' => '1'],
            request: ['method' => 'POST', 'url' => 'Patient'],
            response: ['status' => '201', 'location' => 'Patient/1'],
        );

        $array = $entry->toArray();

        self::assertSame('Patient/1', $array['fullUrl']);
        self::assertSame('Patient', $array['resource']['resourceType']);
        self::assertSame('POST', $array['request']['method']);
        self::assertSame('201', $array['response']['status']);
    }

    #[Test]
    public function bundleEntryFromArrayRoundTrip(): void
    {
        $data = [
            'fullUrl' => 'Observation/42',
            'resource' => ['resourceType' => 'Observation', 'status' => 'final'],
        ];

        $entry = BundleEntry::fromArray($data);

        self::assertSame('Observation/42', $entry->fullUrl);
        self::assertSame('Observation', $entry->resource['resourceType']);
        self::assertNull($entry->request);
        self::assertNull($entry->response);
    }

    #[Test]
    public function bundleEntryFromArrayIgnoresNonStringFullUrl(): void
    {
        $entry = BundleEntry::fromArray(['fullUrl' => 42]);

        self::assertNull($entry->fullUrl);
    }

    // --- BundleLink ---

    #[Test]
    public function bundleLinkToArray(): void
    {
        $link = new BundleLink(relation: 'next', url: 'https://fhir.example.com?page=2');

        $array = $link->toArray();

        self::assertSame('next', $array['relation']);
        self::assertSame('https://fhir.example.com?page=2', $array['url']);
    }

    #[Test]
    public function bundleLinkFromArray(): void
    {
        $link = BundleLink::fromArray([
            'relation' => 'previous',
            'url' => 'https://fhir.example.com?page=1',
        ]);

        self::assertSame('previous', $link->relation);
        self::assertSame('https://fhir.example.com?page=1', $link->url);
    }
}
