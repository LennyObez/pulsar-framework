<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psd2\Exception\Psd2Exception;

final class Psd2ExceptionTest extends TestCase
{
    /**
     * @param list<mixed> $args
     */
    #[Test]
    #[DataProvider('factoryMethodProvider')]
    public function factoryMethodsReturnCorrectMessageType(string $method, array $args, string $expectedSubstring): void
    {
        /** @var Psd2Exception $exception */
        $exception = Psd2Exception::$method(...$args);

        self::assertInstanceOf(Psd2Exception::class, $exception);
        self::assertStringContainsString($expectedSubstring, $exception->getMessage());
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function factoryMethodProvider(): iterable
    {
        yield 'challengeNotFound' => ['challengeNotFound', ['ch_001'], 'not found'];
        yield 'challengeExpired' => ['challengeExpired', ['ch_001'], 'expired'];
        yield 'dynamicLinkMismatch' => ['dynamicLinkMismatch', ['ch_001'], 'do not match'];
        yield 'invalidAuthenticationCode' => ['invalidAuthenticationCode', ['ch_001'], 'Invalid authentication code'];
        yield 'certificateParseFailure' => ['certificateParseFailure', ['bad format'], 'Failed to parse'];
        yield 'certificateExpired' => ['certificateExpired', ['SN123'], 'expired'];
        yield 'certificateNotQualified' => ['certificateNotQualified', ['SN123'], 'not a qualified'];
        yield 'unauthorizedProvider' => ['unauthorizedProvider', ['AUTH123'], 'not authorized'];
        yield 'scaRequired' => ['scaRequired', ['tx_001'], 'SCA is required'];
    }
}
