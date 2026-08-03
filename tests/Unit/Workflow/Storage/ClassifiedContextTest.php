<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Workflow\Storage;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Workflow\Storage\ClassificationLevel;
use Pulsar\Workflow\Storage\ClassifiedContext;
use RuntimeException;

#[CoversClass(ClassifiedContext::class)]
#[CoversClass(ClassificationLevel::class)]
final class ClassifiedContextTest extends TestCase
{
    #[Test]
    public function test_empty_context_has_no_keys(): void
    {
        $context = new ClassifiedContext();

        self::assertSame([], $context->keys());
        self::assertSame([], $context->toArray());
    }

    #[Test]
    public function test_set_returns_new_instance(): void
    {
        $original = new ClassifiedContext();
        $updated = $original->set('name', 'John', ClassificationLevel::Pii);

        self::assertNotSame($original, $updated);
        self::assertFalse($original->has('name'));
        self::assertTrue($updated->has('name'));
    }

    #[Test]
    public function test_get_returns_stored_value(): void
    {
        $context = new ClassifiedContext()
            ->set('amount', 150.50, ClassificationLevel::Internal);

        self::assertSame(150.50, $context->get('amount'));
    }

    #[Test]
    public function test_get_throws_for_missing_field(): void
    {
        $context = new ClassifiedContext();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('does not exist');

        $context->get('nonexistent');
    }

    #[Test]
    public function test_has_returns_true_for_existing_field(): void
    {
        $context = new ClassifiedContext()
            ->set('key', 'value', ClassificationLevel::Public);

        self::assertTrue($context->has('key'));
    }

    #[Test]
    public function test_has_returns_false_for_missing_field(): void
    {
        $context = new ClassifiedContext();

        self::assertFalse($context->has('key'));
    }

    #[Test]
    public function test_get_classification_returns_level(): void
    {
        $context = new ClassifiedContext()
            ->set('ssn', '123-45-6789', ClassificationLevel::Pii);

        self::assertSame(ClassificationLevel::Pii, $context->getClassification('ssn'));
    }

    #[Test]
    public function test_get_classification_throws_for_missing_field(): void
    {
        $context = new ClassifiedContext();

        $this->expectException(InvalidArgumentException::class);

        $context->getClassification('nonexistent');
    }

    #[Test]
    public function test_redact_for_export_at_public_level(): void
    {
        $context = new ClassifiedContext()
            ->set('public_field', 'visible', ClassificationLevel::Public)
            ->set('internal_field', 'hidden', ClassificationLevel::Internal)
            ->set('pii_field', 'secret', ClassificationLevel::Pii);

        $exported = $context->redactForExport(ClassificationLevel::Public);

        self::assertSame(['public_field' => 'visible'], $exported);
    }

    #[Test]
    public function test_redact_for_export_at_internal_level(): void
    {
        $context = new ClassifiedContext()
            ->set('public_field', 'visible', ClassificationLevel::Public)
            ->set('internal_field', 'visible', ClassificationLevel::Internal)
            ->set('restricted_field', 'hidden', ClassificationLevel::Restricted)
            ->set('pii_field', 'hidden', ClassificationLevel::Pii);

        $exported = $context->redactForExport(ClassificationLevel::Internal);

        self::assertArrayHasKey('public_field', $exported);
        self::assertArrayHasKey('internal_field', $exported);
        self::assertArrayNotHasKey('restricted_field', $exported);
        self::assertArrayNotHasKey('pii_field', $exported);
    }

    #[Test]
    public function test_redact_for_export_at_restricted_level(): void
    {
        $context = new ClassifiedContext()
            ->set('public_field', 'a', ClassificationLevel::Public)
            ->set('restricted_field', 'b', ClassificationLevel::Restricted)
            ->set('pii_field', 'c', ClassificationLevel::Pii);

        $exported = $context->redactForExport(ClassificationLevel::Restricted);

        self::assertArrayHasKey('public_field', $exported);
        self::assertArrayHasKey('restricted_field', $exported);
        self::assertArrayNotHasKey('pii_field', $exported);
    }

    #[Test]
    public function test_redact_for_export_at_pii_level_includes_all(): void
    {
        $context = new ClassifiedContext()
            ->set('public_field', 'a', ClassificationLevel::Public)
            ->set('pii_field', 'b', ClassificationLevel::Pii);

        $exported = $context->redactForExport(ClassificationLevel::Pii);

        self::assertCount(2, $exported);
    }

    #[Test]
    public function test_redact_for_export_defaults_to_internal(): void
    {
        $context = new ClassifiedContext()
            ->set('public_field', 'visible', ClassificationLevel::Public)
            ->set('internal_field', 'visible', ClassificationLevel::Internal)
            ->set('restricted_field', 'hidden', ClassificationLevel::Restricted);

        $exported = $context->redactForExport();

        self::assertCount(2, $exported);
    }

    #[Test]
    public function test_to_array_returns_all_values(): void
    {
        $context = new ClassifiedContext()
            ->set('a', 1, ClassificationLevel::Public)
            ->set('b', 2, ClassificationLevel::Pii);

        self::assertSame(['a' => 1, 'b' => 2], $context->toArray());
    }

    #[Test]
    public function test_keys_returns_all_field_names(): void
    {
        $context = new ClassifiedContext()
            ->set('first', 'x', ClassificationLevel::Public)
            ->set('second', 'y', ClassificationLevel::Internal);

        self::assertSame(['first', 'second'], $context->keys());
    }

    #[Test]
    public function test_classification_map_returns_all_levels(): void
    {
        $context = new ClassifiedContext()
            ->set('a', 1, ClassificationLevel::Public)
            ->set('b', 2, ClassificationLevel::Pii);

        $map = $context->classificationMap();

        self::assertSame(ClassificationLevel::Public, $map['a']);
        self::assertSame(ClassificationLevel::Pii, $map['b']);
    }

    #[Test]
    public function test_serialize_produces_storable_format(): void
    {
        $context = new ClassifiedContext()
            ->set('name', 'John', ClassificationLevel::Pii)
            ->set('status', 'active', ClassificationLevel::Public);

        $serialized = $context->serialize();

        self::assertArrayHasKey('values', $serialized);
        self::assertArrayHasKey('classifications', $serialized);
        self::assertSame('John', $serialized['values']['name']);
        self::assertSame('pii', $serialized['classifications']['name']);
        self::assertSame('public', $serialized['classifications']['status']);
    }

    #[Test]
    public function test_from_serialized_reconstructs_context(): void
    {
        $data = [
            'values' => ['name' => 'John', 'status' => 'active'],
            'classifications' => ['name' => 'pii', 'status' => 'public'],
        ];

        $context = ClassifiedContext::fromSerialized($data);

        self::assertSame('John', $context->get('name'));
        self::assertSame(ClassificationLevel::Pii, $context->getClassification('name'));
        self::assertSame('active', $context->get('status'));
        self::assertSame(ClassificationLevel::Public, $context->getClassification('status'));
    }

    #[Test]
    public function test_from_serialized_handles_empty_data(): void
    {
        $context = ClassifiedContext::fromSerialized([]);

        self::assertSame([], $context->keys());
    }

    #[Test]
    public function test_serialize_roundtrip_preserves_data(): void
    {
        $original = new ClassifiedContext()
            ->set('key1', 'val1', ClassificationLevel::Internal)
            ->set('key2', 42, ClassificationLevel::Restricted);

        $restored = ClassifiedContext::fromSerialized($original->serialize());

        self::assertSame($original->toArray(), $restored->toArray());
        self::assertSame('val1', $restored->get('key1'));
        self::assertSame(ClassificationLevel::Internal, $restored->getClassification('key1'));
        self::assertSame(42, $restored->get('key2'));
        self::assertSame(ClassificationLevel::Restricted, $restored->getClassification('key2'));
    }

    #[Test]
    public function test_set_overwrites_existing_field(): void
    {
        $context = new ClassifiedContext()
            ->set('key', 'old', ClassificationLevel::Public)
            ->set('key', 'new', ClassificationLevel::Restricted);

        self::assertSame('new', $context->get('key'));
        self::assertSame(ClassificationLevel::Restricted, $context->getClassification('key'));
    }

    #[Test]
    public function test_serialize_wraps_encryption_failure(): void
    {
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('encrypt')->willThrowException(new RuntimeException('Key not available'));

        $context = new ClassifiedContext()
            ->set('secret', 'value', ClassificationLevel::Restricted);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Failed to encrypt workflow context field "secret"');

        $context->serialize($encryptor);
    }

    #[Test]
    public function test_from_serialized_wraps_decryption_failure(): void
    {
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('decrypt')->willThrowException(new RuntimeException('Decryption failed'));

        $data = [
            'values' => ['secret' => 'encrypted-blob'],
            'classifications' => ['secret' => 'restricted'],
            'encrypted' => ['secret'],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Failed to decrypt workflow context field "secret"');

        ClassifiedContext::fromSerialized($data, $encryptor);
    }

    // --- ClassificationLevel ---

    #[Test]
    public function test_classification_level_is_at_or_below(): void
    {
        self::assertTrue(ClassificationLevel::Public->isAtOrBelow(ClassificationLevel::Public));
        self::assertTrue(ClassificationLevel::Public->isAtOrBelow(ClassificationLevel::Pii));
        self::assertTrue(ClassificationLevel::Internal->isAtOrBelow(ClassificationLevel::Internal));
        self::assertFalse(ClassificationLevel::Restricted->isAtOrBelow(ClassificationLevel::Internal));
        self::assertFalse(ClassificationLevel::Pii->isAtOrBelow(ClassificationLevel::Restricted));
    }

    /**
     * @return iterable<string, array{ClassificationLevel, string}>
     */
    public static function classificationLevelProvider(): iterable
    {
        yield 'public' => [ClassificationLevel::Public, 'public'];
        yield 'internal' => [ClassificationLevel::Internal, 'internal'];
        yield 'restricted' => [ClassificationLevel::Restricted, 'restricted'];
        yield 'pii' => [ClassificationLevel::Pii, 'pii'];
    }

    #[Test]
    #[DataProvider('classificationLevelProvider')]
    public function test_classification_level_has_correct_string_value(
        ClassificationLevel $level,
        string $expectedValue,
    ): void {
        self::assertSame($expectedValue, $level->value);
    }
}
