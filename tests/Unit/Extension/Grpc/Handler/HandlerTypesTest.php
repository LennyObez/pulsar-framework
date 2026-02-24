<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Handler\MethodDescriptor;
use Pulsar\Extension\Grpc\Handler\MethodType;

#[CoversClass(MethodDescriptor::class)]
#[CoversClass(MethodType::class)]
final class HandlerTypesTest extends TestCase
{
    // --- MethodType ---

    #[Test]
    public function methodTypeValues(): void
    {
        self::assertSame('unary', MethodType::Unary->value);
        self::assertSame('server_streaming', MethodType::ServerStreaming->value);
        self::assertSame('client_streaming', MethodType::ClientStreaming->value);
        self::assertSame('bidirectional_streaming', MethodType::BidirectionalStreaming->value);
    }

    #[Test]
    public function hasClientStream(): void
    {
        self::assertFalse(MethodType::Unary->hasClientStream());
        self::assertFalse(MethodType::ServerStreaming->hasClientStream());
        self::assertTrue(MethodType::ClientStreaming->hasClientStream());
        self::assertTrue(MethodType::BidirectionalStreaming->hasClientStream());
    }

    #[Test]
    public function hasServerStream(): void
    {
        self::assertFalse(MethodType::Unary->hasServerStream());
        self::assertTrue(MethodType::ServerStreaming->hasServerStream());
        self::assertFalse(MethodType::ClientStreaming->hasServerStream());
        self::assertTrue(MethodType::BidirectionalStreaming->hasServerStream());
    }

    // --- MethodDescriptor ---

    #[Test]
    public function constructionWithAllFields(): void
    {
        $descriptor = new MethodDescriptor(
            name: 'GetAccount',
            fullName: '/banking.v1.AccountService/GetAccount',
            type: MethodType::Unary,
            inputType: 'banking.v1.GetAccountRequest',
            outputType: 'banking.v1.GetAccountResponse',
            handler: 'App\\Grpc\\AccountHandler::getAccount',
        );

        self::assertSame('GetAccount', $descriptor->name);
        self::assertSame('/banking.v1.AccountService/GetAccount', $descriptor->fullName);
        self::assertSame(MethodType::Unary, $descriptor->type);
        self::assertSame('banking.v1.GetAccountRequest', $descriptor->inputType);
        self::assertSame('banking.v1.GetAccountResponse', $descriptor->outputType);
    }

    #[Test]
    public function fromArray(): void
    {
        $descriptor = MethodDescriptor::fromArray([
            'name' => 'TransferFunds',
            'full_name' => '/banking.v1.TransferService/TransferFunds',
            'type' => 'unary',
            'input_type' => 'banking.v1.TransferRequest',
            'output_type' => 'banking.v1.TransferResponse',
            'handler' => 'App\\Grpc\\TransferHandler::transfer',
        ]);

        self::assertSame('TransferFunds', $descriptor->name);
        self::assertSame(MethodType::Unary, $descriptor->type);
    }

    #[Test]
    public function toArrayRoundTrip(): void
    {
        $descriptor = new MethodDescriptor(
            name: 'ListTransactions',
            fullName: '/banking.v1.TransactionService/ListTransactions',
            type: MethodType::ServerStreaming,
            inputType: 'banking.v1.ListTransactionsRequest',
            outputType: 'banking.v1.Transaction',
            handler: 'App\\Grpc\\TransactionHandler::list',
        );

        $array = $descriptor->toArray();
        self::assertSame('ListTransactions', $array['name']);
        self::assertSame('server_streaming', $array['type']);
    }
}
