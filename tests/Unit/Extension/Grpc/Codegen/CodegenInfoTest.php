<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Codegen;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Codegen\MethodInfo;
use Pulsar\Extension\Grpc\Codegen\ServiceInfo;

#[CoversClass(MethodInfo::class)]
#[CoversClass(ServiceInfo::class)]
final class CodegenInfoTest extends TestCase
{
    #[Test]
    public function methodInfoConstruction(): void
    {
        $method = new MethodInfo(
            name: 'GetTransaction',
            inputType: 'banking.v1.GetTransactionRequest',
            outputType: 'banking.v1.TransactionResponse',
        );

        self::assertSame('GetTransaction', $method->name);
        self::assertSame('banking.v1.GetTransactionRequest', $method->inputType);
        self::assertSame('banking.v1.TransactionResponse', $method->outputType);
    }

    #[Test]
    public function serviceInfoConstruction(): void
    {
        $methods = [
            new MethodInfo('GetAccount', 'banking.v1.GetAccountReq', 'banking.v1.AccountRes'),
            new MethodInfo('ListAccounts', 'banking.v1.ListAccountsReq', 'banking.v1.AccountRes'),
        ];

        $service = new ServiceInfo(
            name: 'AccountService',
            protoNamespace: 'banking.v1',
            methods: $methods,
        );

        self::assertSame('AccountService', $service->name);
        self::assertSame('banking.v1', $service->protoNamespace);
        self::assertCount(2, $service->methods);
        self::assertSame('GetAccount', $service->methods[0]->name);
    }
}
