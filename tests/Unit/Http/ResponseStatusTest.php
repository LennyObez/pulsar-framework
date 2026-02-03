<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\ResponseStatus;

#[CoversClass(ResponseStatus::class)]
final class ResponseStatusTest extends TestCase
{
    #[Test]
    public function reasonPhraseReturnsCorrectPhraseForAllCases(): void
    {
        $expected = [
            100 => 'Continue',
            101 => 'Switching Protocols',
            102 => 'Processing',
            103 => 'Early Hints',
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            203 => 'Non-Authoritative Information',
            204 => 'No Content',
            205 => 'Reset Content',
            206 => 'Partial Content',
            207 => 'Multi-Status',
            208 => 'Already Reported',
            226 => 'IM Used',
            300 => 'Multiple Choices',
            301 => 'Moved Permanently',
            302 => 'Found',
            303 => 'See Other',
            304 => 'Not Modified',
            305 => 'Use Proxy',
            307 => 'Temporary Redirect',
            308 => 'Permanent Redirect',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            402 => 'Payment Required',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            406 => 'Not Acceptable',
            407 => 'Proxy Authentication Required',
            408 => 'Request Timeout',
            409 => 'Conflict',
            410 => 'Gone',
            411 => 'Length Required',
            412 => 'Precondition Failed',
            413 => 'Payload Too Large',
            414 => 'URI Too Long',
            415 => 'Unsupported Media Type',
            416 => 'Range Not Satisfiable',
            417 => 'Expectation Failed',
            418 => "I'm a teapot",
            421 => 'Misdirected Request',
            422 => 'Unprocessable Entity',
            423 => 'Locked',
            424 => 'Failed Dependency',
            425 => 'Too Early',
            426 => 'Upgrade Required',
            428 => 'Precondition Required',
            429 => 'Too Many Requests',
            431 => 'Request Header Fields Too Large',
            451 => 'Unavailable For Legal Reasons',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            505 => 'HTTP Version Not Supported',
            506 => 'Variant Also Negotiates',
            507 => 'Insufficient Storage',
            508 => 'Loop Detected',
            510 => 'Not Extended',
            511 => 'Network Authentication Required',
        ];

        foreach ($expected as $code => $phrase) {
            $status = ResponseStatus::from($code);
            self::assertSame($phrase, $status->reasonPhrase(), "Status {$code} should have phrase '{$phrase}'");
        }
    }

    #[Test]
    public function isInformationalReturnsTrueFor1xx(): void
    {
        self::assertTrue(ResponseStatus::Continue->isInformational());
        self::assertTrue(ResponseStatus::SwitchingProtocols->isInformational());
        self::assertTrue(ResponseStatus::EarlyHints->isInformational());
    }

    #[Test]
    public function isInformationalReturnsFalseForNon1xx(): void
    {
        self::assertFalse(ResponseStatus::OK->isInformational());
        self::assertFalse(ResponseStatus::NotFound->isInformational());
        self::assertFalse(ResponseStatus::InternalServerError->isInformational());
    }

    #[Test]
    public function isSuccessfulReturnsTrueFor2xx(): void
    {
        self::assertTrue(ResponseStatus::OK->isSuccessful());
        self::assertTrue(ResponseStatus::Created->isSuccessful());
        self::assertTrue(ResponseStatus::NoContent->isSuccessful());
    }

    #[Test]
    public function isSuccessfulReturnsFalseForNon2xx(): void
    {
        self::assertFalse(ResponseStatus::Continue->isSuccessful());
        self::assertFalse(ResponseStatus::Found->isSuccessful());
        self::assertFalse(ResponseStatus::BadRequest->isSuccessful());
    }

    #[Test]
    public function isRedirectionReturnsTrueFor3xx(): void
    {
        self::assertTrue(ResponseStatus::MovedPermanently->isRedirection());
        self::assertTrue(ResponseStatus::Found->isRedirection());
        self::assertTrue(ResponseStatus::TemporaryRedirect->isRedirection());
    }

    #[Test]
    public function isRedirectionReturnsFalseForNon3xx(): void
    {
        self::assertFalse(ResponseStatus::OK->isRedirection());
        self::assertFalse(ResponseStatus::NotFound->isRedirection());
    }

    #[Test]
    public function isClientErrorReturnsTrueFor4xx(): void
    {
        self::assertTrue(ResponseStatus::BadRequest->isClientError());
        self::assertTrue(ResponseStatus::NotFound->isClientError());
        self::assertTrue(ResponseStatus::TooManyRequests->isClientError());
    }

    #[Test]
    public function isClientErrorReturnsFalseForNon4xx(): void
    {
        self::assertFalse(ResponseStatus::OK->isClientError());
        self::assertFalse(ResponseStatus::InternalServerError->isClientError());
    }

    #[Test]
    public function isServerErrorReturnsTrueFor5xx(): void
    {
        self::assertTrue(ResponseStatus::InternalServerError->isServerError());
        self::assertTrue(ResponseStatus::BadGateway->isServerError());
        self::assertTrue(ResponseStatus::ServiceUnavailable->isServerError());
    }

    #[Test]
    public function isServerErrorReturnsFalseForNon5xx(): void
    {
        self::assertFalse(ResponseStatus::OK->isServerError());
        self::assertFalse(ResponseStatus::NotFound->isServerError());
    }

    #[Test]
    public function isErrorReturnsTrueFor4xxAnd5xx(): void
    {
        self::assertTrue(ResponseStatus::BadRequest->isError());
        self::assertTrue(ResponseStatus::NotFound->isError());
        self::assertTrue(ResponseStatus::InternalServerError->isError());
        self::assertTrue(ResponseStatus::ServiceUnavailable->isError());
    }

    #[Test]
    public function isErrorReturnsFalseFor1xx2xx3xx(): void
    {
        self::assertFalse(ResponseStatus::Continue->isError());
        self::assertFalse(ResponseStatus::OK->isError());
        self::assertFalse(ResponseStatus::MovedPermanently->isError());
    }
}
