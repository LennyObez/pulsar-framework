<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Eidas\Exception\EidasException;

final class EidasExceptionTest extends TestCase
{
    /**
     * @param list<mixed> $args
     */
    #[Test]
    #[DataProvider('factoryMethodProvider')]
    public function factoryMethodsReturnCorrectType(string $method, array $args, string $expectedSubstring): void
    {
        /** @var EidasException $exception */
        $exception = EidasException::$method(...$args);

        self::assertInstanceOf(EidasException::class, $exception);
        self::assertStringContainsString($expectedSubstring, $exception->getMessage());
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function factoryMethodProvider(): iterable
    {
        yield 'signatureVerificationFailed' => ['signatureVerificationFailed', ['bad sig'], 'Signature verification failed'];
        yield 'unsupportedSignatureFormat' => ['unsupportedSignatureFormat', ['foo'], 'Unsupported signature format'];
        yield 'sealVerificationFailed' => ['sealVerificationFailed', ['bad seal'], 'Electronic seal verification failed'];
        yield 'timestampRequestFailed' => ['timestampRequestFailed', ['timeout'], 'Timestamp request failed'];
        yield 'timestampVerificationFailed' => ['timestampVerificationFailed', ['mismatch'], 'Timestamp verification failed'];
        yield 'deliveryFailed' => ['deliveryFailed', ['msg_001', 'timeout'], 'Registered delivery failed'];
        yield 'insufficientAssuranceLevel' => ['insufficientAssuranceLevel', ['high', 'low'], 'Insufficient assurance level'];
    }
}
