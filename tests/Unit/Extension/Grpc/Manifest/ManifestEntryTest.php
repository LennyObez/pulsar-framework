<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Manifest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Manifest\ManifestEntry;

#[CoversClass(ManifestEntry::class)]
final class ManifestEntryTest extends TestCase
{
    #[Test]
    public function construction(): void
    {
        $methods = [
            [
                'name' => 'GetBalance',
                'full_name' => '/banking.v1.AccountService/GetBalance',
                'type' => 'unary',
                'input_type' => 'banking.v1.GetBalanceRequest',
                'output_type' => 'banking.v1.BalanceResponse',
                'handler' => 'App\\Grpc\\AccountHandler::getBalance',
            ],
        ];

        $entry = new ManifestEntry(
            serviceName: 'banking.v1.AccountService',
            handlerClass: 'App\\Grpc\\AccountHandler',
            methods: $methods,
        );

        self::assertSame('banking.v1.AccountService', $entry->serviceName);
        self::assertSame('App\\Grpc\\AccountHandler', $entry->handlerClass);
        self::assertCount(1, $entry->methods);
    }

    #[Test]
    public function fromArray(): void
    {
        $data = [
            'service_name' => 'health.v1.PatientService',
            'handler_class' => 'App\\Grpc\\PatientHandler',
            'methods' => [
                [
                    'name' => 'GetPatient',
                    'full_name' => '/health.v1.PatientService/GetPatient',
                    'type' => 'unary',
                    'input_type' => 'health.v1.GetPatientRequest',
                    'output_type' => 'health.v1.PatientResponse',
                    'handler' => 'App\\Grpc\\PatientHandler::get',
                ],
            ],
        ];

        $entry = ManifestEntry::fromArray($data);

        self::assertSame('health.v1.PatientService', $entry->serviceName);
        self::assertSame('App\\Grpc\\PatientHandler', $entry->handlerClass);
        self::assertCount(1, $entry->methods);
    }

    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $entry = ManifestEntry::fromArray([]);

        self::assertSame('', $entry->serviceName);
        self::assertSame('', $entry->handlerClass);
        self::assertSame([], $entry->methods);
    }

    #[Test]
    public function toArrayRoundTrip(): void
    {
        $methods = [
            [
                'name' => 'Transfer',
                'full_name' => '/banking.v1.TransferService/Transfer',
                'type' => 'unary',
                'input_type' => 'banking.v1.TransferRequest',
                'output_type' => 'banking.v1.TransferResponse',
                'handler' => 'App\\Grpc\\TransferHandler::transfer',
            ],
        ];

        $entry = new ManifestEntry('banking.v1.TransferService', 'App\\Grpc\\TransferHandler', $methods);
        $array = $entry->toArray();

        self::assertSame('banking.v1.TransferService', $array['service_name']);
        self::assertSame($methods, $array['methods']);
    }
}
