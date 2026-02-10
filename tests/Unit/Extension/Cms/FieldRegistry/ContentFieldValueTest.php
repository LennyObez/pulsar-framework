<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\FieldRegistry;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\FieldRegistry\ContentFieldValue;
use ReflectionClass;

#[CoversClass(ContentFieldValue::class)]
final class ContentFieldValueTest extends TestCase
{
    #[Test]
    public function constructionWithStringValue(): void
    {
        $value = new ContentFieldValue(
            id: 'val-1',
            contentId: 'content-1',
            fieldId: 'field-1',
            locale: 'en',
            valueString: 'Hello World',
            valueInt: null,
            valueFloat: null,
            valueBool: null,
            valueDatetime: null,
            valueJson: null,
        );

        self::assertSame('val-1', $value->id);
        self::assertSame('content-1', $value->contentId);
        self::assertSame('field-1', $value->fieldId);
        self::assertSame('en', $value->locale);
        self::assertSame('Hello World', $value->valueString);
        self::assertNull($value->valueInt);
        self::assertNull($value->valueFloat);
        self::assertNull($value->valueBool);
        self::assertNull($value->valueDatetime);
        self::assertNull($value->valueJson);
    }

    #[Test]
    public function constructionWithIntValue(): void
    {
        $value = new ContentFieldValue(
            id: 'val-2',
            contentId: 'content-1',
            fieldId: 'field-int',
            locale: null,
            valueString: null,
            valueInt: 42,
            valueFloat: null,
            valueBool: null,
            valueDatetime: null,
            valueJson: null,
        );

        self::assertSame(42, $value->valueInt);
        self::assertNull($value->locale);
    }

    #[Test]
    public function constructionWithFloatValue(): void
    {
        $value = new ContentFieldValue(
            id: 'val-3',
            contentId: 'content-1',
            fieldId: 'field-float',
            locale: null,
            valueString: null,
            valueInt: null,
            valueFloat: 3.14,
            valueBool: null,
            valueDatetime: null,
            valueJson: null,
        );

        self::assertSame(3.14, $value->valueFloat);
    }

    #[Test]
    public function constructionWithBoolValue(): void
    {
        $value = new ContentFieldValue(
            id: 'val-4',
            contentId: 'content-1',
            fieldId: 'field-bool',
            locale: null,
            valueString: null,
            valueInt: null,
            valueFloat: null,
            valueBool: true,
            valueDatetime: null,
            valueJson: null,
        );

        self::assertTrue($value->valueBool);
    }

    #[Test]
    public function constructionWithBoolFalseValue(): void
    {
        $value = new ContentFieldValue(
            id: 'val-5',
            contentId: 'content-1',
            fieldId: 'field-bool',
            locale: null,
            valueString: null,
            valueInt: null,
            valueFloat: null,
            valueBool: false,
            valueDatetime: null,
            valueJson: null,
        );

        self::assertFalse($value->valueBool);
    }

    #[Test]
    public function constructionWithDatetimeValue(): void
    {
        $datetime = new DateTimeImmutable('2024-06-15T10:30:00+00:00');

        $value = new ContentFieldValue(
            id: 'val-6',
            contentId: 'content-1',
            fieldId: 'field-date',
            locale: null,
            valueString: null,
            valueInt: null,
            valueFloat: null,
            valueBool: null,
            valueDatetime: $datetime,
            valueJson: null,
        );

        self::assertSame($datetime, $value->valueDatetime);
        self::assertSame('2024-06-15T10:30:00+00:00', $value->valueDatetime->format('c'));
    }

    #[Test]
    public function constructionWithJsonValue(): void
    {
        $json = ['key' => 'value', 'nested' => ['a' => 1, 'b' => 2]];

        $value = new ContentFieldValue(
            id: 'val-7',
            contentId: 'content-1',
            fieldId: 'field-json',
            locale: null,
            valueString: null,
            valueInt: null,
            valueFloat: null,
            valueBool: null,
            valueDatetime: null,
            valueJson: $json,
        );

        self::assertSame($json, $value->valueJson);
    }

    #[Test]
    public function constructionWithNullLocaleForNonTranslatableField(): void
    {
        $value = new ContentFieldValue(
            id: 'val-8',
            contentId: 'content-1',
            fieldId: 'field-global',
            locale: null,
            valueString: 'global value',
            valueInt: null,
            valueFloat: null,
            valueBool: null,
            valueDatetime: null,
            valueJson: null,
        );

        self::assertNull($value->locale);
    }

    #[Test]
    public function constructionWithLocaleForTranslatableField(): void
    {
        $value = new ContentFieldValue(
            id: 'val-9',
            contentId: 'content-1',
            fieldId: 'field-title',
            locale: 'de-DE',
            valueString: 'Hallo Welt',
            valueInt: null,
            valueFloat: null,
            valueBool: null,
            valueDatetime: null,
            valueJson: null,
        );

        self::assertSame('de-DE', $value->locale);
        self::assertSame('Hallo Welt', $value->valueString);
    }

    #[Test]
    public function constructionWithAllNullValues(): void
    {
        $value = new ContentFieldValue(
            id: 'val-10',
            contentId: 'content-1',
            fieldId: 'field-empty',
            locale: null,
            valueString: null,
            valueInt: null,
            valueFloat: null,
            valueBool: null,
            valueDatetime: null,
            valueJson: null,
        );

        self::assertNull($value->valueString);
        self::assertNull($value->valueInt);
        self::assertNull($value->valueFloat);
        self::assertNull($value->valueBool);
        self::assertNull($value->valueDatetime);
        self::assertNull($value->valueJson);
    }

    #[Test]
    public function constructionWithZeroIntValue(): void
    {
        $value = new ContentFieldValue(
            id: 'val-11',
            contentId: 'content-1',
            fieldId: 'field-int',
            locale: null,
            valueString: null,
            valueInt: 0,
            valueFloat: null,
            valueBool: null,
            valueDatetime: null,
            valueJson: null,
        );

        self::assertSame(0, $value->valueInt);
    }

    #[Test]
    public function constructionWithEmptyStringValue(): void
    {
        $value = new ContentFieldValue(
            id: 'val-12',
            contentId: 'content-1',
            fieldId: 'field-str',
            locale: null,
            valueString: '',
            valueInt: null,
            valueFloat: null,
            valueBool: null,
            valueDatetime: null,
            valueJson: null,
        );

        self::assertSame('', $value->valueString);
    }

    #[Test]
    public function constructionWithEmptyJsonArray(): void
    {
        $value = new ContentFieldValue(
            id: 'val-13',
            contentId: 'content-1',
            fieldId: 'field-json',
            locale: null,
            valueString: null,
            valueInt: null,
            valueFloat: null,
            valueBool: null,
            valueDatetime: null,
            valueJson: [],
        );

        self::assertSame([], $value->valueJson);
    }

    #[Test]
    public function isReadonly(): void
    {
        $value = new ContentFieldValue(
            id: 'val-14',
            contentId: 'content-1',
            fieldId: 'field-1',
            locale: 'en',
            valueString: 'test',
            valueInt: null,
            valueFloat: null,
            valueBool: null,
            valueDatetime: null,
            valueJson: null,
        );

        $reflection = new ReflectionClass($value);
        self::assertTrue($reflection->isReadOnly());
    }
}
