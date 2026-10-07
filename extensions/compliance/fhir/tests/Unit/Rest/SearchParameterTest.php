<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit\Rest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Rest\SearchParameter;

#[CoversClass(SearchParameter::class)]
final class SearchParameterTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $param = new SearchParameter('name', 'string', 'A name', 'Patient.name');

        self::assertSame('name', $param->name);
        self::assertSame('string', $param->type);
        self::assertSame('A name', $param->description);
        self::assertSame('Patient.name', $param->expression);
    }

    #[Test]
    public function expressionDefaultsToNull(): void
    {
        $param = new SearchParameter('code', 'token', 'A code');

        self::assertNull($param->expression);
    }

    #[Test]
    public function toArrayWithoutExpression(): void
    {
        $param = new SearchParameter('gender', 'token', 'Gender filter');
        $array = $param->toArray();

        self::assertSame(['name' => 'gender', 'type' => 'token', 'description' => 'Gender filter'], $array);
        self::assertArrayNotHasKey('expression', $array);
    }

    #[Test]
    public function toArrayWithExpression(): void
    {
        $param = new SearchParameter('birthdate', 'date', 'DOB', 'Patient.birthDate');
        $array = $param->toArray();

        self::assertSame('Patient.birthDate', $array['expression']);
    }
}
