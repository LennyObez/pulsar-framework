<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ErrorHandling\HttpException;
use Pulsar\Http\ResponseStatus;
use Pulsar\Http\Validation\ValidationException;
use Pulsar\Http\Validation\ValidationResult;
use Pulsar\Http\Validation\Violation;

#[CoversClass(ValidationException::class)]
final class ValidationExceptionTest extends TestCase
{
    #[Test]
    public function extendsHttpException(): void
    {
        $result = new ValidationResult();
        $exception = new ValidationException($result);

        self::assertInstanceOf(HttpException::class, $exception);
    }

    #[Test]
    public function statusIs422(): void
    {
        $result = new ValidationResult();
        $exception = new ValidationException($result);

        self::assertSame(ResponseStatus::UnprocessableEntity, $exception->getStatusCode());
        self::assertSame(422, $exception->getCode());
    }

    #[Test]
    public function defaultMessage(): void
    {
        $result = new ValidationResult();
        $exception = new ValidationException($result);

        self::assertSame('Validation Failed', $exception->getMessage());
    }

    #[Test]
    public function customMessage(): void
    {
        $result = new ValidationResult();
        $exception = new ValidationException($result, 'Custom validation error');

        self::assertSame('Custom validation error', $exception->getMessage());
    }

    #[Test]
    public function resultAccessor(): void
    {
        $result = new ValidationResult([
            new Violation('email', 'Required.', 'required'),
        ]);
        $exception = new ValidationException($result);

        self::assertSame($result, $exception->result());
    }

    #[Test]
    public function violationsReturnsSerializedArray(): void
    {
        $result = new ValidationResult([
            new Violation('email', 'Required.', 'required'),
        ]);
        $exception = new ValidationException($result);

        self::assertSame([
            ['field' => 'email', 'message' => 'Required.', 'rule' => 'required', 'code' => 'VALIDATION_REQUIRED'],
        ], $exception->violations());
    }
}
