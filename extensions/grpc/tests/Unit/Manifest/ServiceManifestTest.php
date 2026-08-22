<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Manifest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Manifest\ManifestEntry;
use Pulsar\Extension\Grpc\Manifest\ServiceManifest;

#[CoversClass(ServiceManifest::class)]
#[CoversClass(ManifestEntry::class)]
final class ServiceManifestTest extends TestCase
{
    #[Test]
    public function constructsWithDefaults(): void
    {
        $manifest = new ServiceManifest(services: []);

        self::assertSame([], $manifest->services);
        self::assertSame('1.0', $manifest->version);
        self::assertSame('', $manifest->compiledAt);
    }

    #[Test]
    public function constructsWithAllProperties(): void
    {
        $entry = new ManifestEntry(
            serviceName: 'helloworld.Greeter',
            handlerClass: 'App\\Handler\\GreeterHandler',
            methods: [
                [
                    'name' => 'SayHello',
                    'full_name' => '/helloworld.Greeter/SayHello',
                    'type' => 'unary',
                    'input_type' => 'helloworld.HelloRequest',
                    'output_type' => 'helloworld.HelloReply',
                    'handler' => 'App\\Handler\\GreeterHandler::SayHello',
                ],
            ],
        );

        $manifest = new ServiceManifest(
            services: [$entry],
            version: '1.0',
            compiledAt: '2026-03-02T12:00:00+00:00',
        );

        self::assertCount(1, $manifest->services);
        self::assertSame('1.0', $manifest->version);
    }

    #[Test]
    public function toArraySerializesCorrectly(): void
    {
        $entry = new ManifestEntry(
            serviceName: 'test.Service',
            handlerClass: 'App\\TestHandler',
            methods: [],
        );

        $manifest = new ServiceManifest(
            services: [$entry],
            version: '1.0',
            compiledAt: '2026-03-02T12:00:00+00:00',
        );

        $array = $manifest->toArray();

        self::assertSame('1.0', $array['version']);
        self::assertSame('2026-03-02T12:00:00+00:00', $array['compiled_at']);
        self::assertCount(1, $array['services']);
        self::assertSame('test.Service', $array['services'][0]['service_name']);
    }

    #[Test]
    public function fromArrayDeserializesCorrectly(): void
    {
        $data = [
            'services' => [
                [
                    'service_name' => 'test.Service',
                    'handler_class' => 'App\\Handler',
                    'methods' => [],
                ],
            ],
            'version' => '1.0',
            'compiled_at' => '2026-03-02T12:00:00+00:00',
        ];

        $manifest = ServiceManifest::fromArray($data);

        self::assertCount(1, $manifest->services);
        self::assertSame('test.Service', $manifest->services[0]->serviceName);
        self::assertSame('App\\Handler', $manifest->services[0]->handlerClass);
        self::assertSame('1.0', $manifest->version);
    }

    #[Test]
    public function fromArrayHandlesMissingData(): void
    {
        $manifest = ServiceManifest::fromArray([]);

        self::assertSame([], $manifest->services);
        self::assertSame('1.0', $manifest->version);
        self::assertSame('', $manifest->compiledAt);
    }

    #[Test]
    public function toPhpArrayGeneratesValidPhp(): void
    {
        $entry = new ManifestEntry(
            serviceName: 'test.Service',
            handlerClass: 'App\\TestHandler',
            methods: [
                [
                    'name' => 'DoWork',
                    'full_name' => '/test.Service/DoWork',
                    'type' => 'unary',
                    'input_type' => 'test.Request',
                    'output_type' => 'test.Response',
                    'handler' => 'App\\TestHandler::DoWork',
                ],
            ],
        );

        $manifest = new ServiceManifest(
            services: [$entry],
            compiledAt: '2026-03-02T12:00:00+00:00',
        );

        $phpCode = $manifest->toPhpArray();

        self::assertStringStartsWith('<?php', $phpCode);
        self::assertStringContainsString('declare(strict_types=1)', $phpCode);
        self::assertStringContainsString('return', $phpCode);
        self::assertStringContainsString('test.Service', $phpCode);
        self::assertStringContainsString('do not edit', $phpCode);
    }

    #[Test]
    public function roundTrip(): void
    {
        $original = new ServiceManifest(
            services: [
                new ManifestEntry(
                    serviceName: 'pkg.Svc',
                    handlerClass: 'App\\SvcHandler',
                    methods: [
                        [
                            'name' => 'Call',
                            'full_name' => '/pkg.Svc/Call',
                            'type' => 'unary',
                            'input_type' => 'pkg.Req',
                            'output_type' => 'pkg.Res',
                            'handler' => 'App\\SvcHandler::Call',
                        ],
                    ],
                ),
            ],
            version: '1.0',
            compiledAt: '2026-03-02T12:00:00+00:00',
        );

        $restored = ServiceManifest::fromArray($original->toArray());

        self::assertCount(1, $restored->services);
        self::assertSame('pkg.Svc', $restored->services[0]->serviceName);
        self::assertSame('App\\SvcHandler', $restored->services[0]->handlerClass);
        self::assertCount(1, $restored->services[0]->methods);
    }

    #[Test]
    public function manifestEntryFromArrayHandlesMissingData(): void
    {
        $entry = ManifestEntry::fromArray([]);

        self::assertSame('', $entry->serviceName);
        self::assertSame('', $entry->handlerClass);
        self::assertSame([], $entry->methods);
    }

    #[Test]
    public function manifestEntryToArray(): void
    {
        $entry = new ManifestEntry(
            serviceName: 'svc',
            handlerClass: 'Handler',
            methods: [],
        );

        $array = $entry->toArray();

        self::assertSame('svc', $array['service_name']);
        self::assertSame('Handler', $array['handler_class']);
        self::assertSame([], $array['methods']);
    }
}
