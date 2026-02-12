<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Pseudonymization;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Pseudonymization\InMemoryPseudonymLookup;

#[CoversClass(InMemoryPseudonymLookup::class)]
final class InMemoryPseudonymLookupTest extends TestCase
{
    private InMemoryPseudonymLookup $lookup;

    protected function setUp(): void
    {
        $this->lookup = new InMemoryPseudonymLookup();
    }

    #[Test]
    public function storeAndFindBySubjectId(): void
    {
        $this->lookup->store('user-1', 'pseudo-1', 'salt-1');

        $mapping = $this->lookup->findBySubjectId('user-1');

        self::assertNotNull($mapping);
        self::assertSame('user-1', $mapping->subjectId);
        self::assertSame('pseudo-1', $mapping->pseudonym);
        self::assertSame('salt-1', $mapping->encryptedSalt);
    }

    #[Test]
    public function findBySubjectIdReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->lookup->findBySubjectId('nonexistent'));
    }

    #[Test]
    public function storeAndFindByPseudonym(): void
    {
        $this->lookup->store('user-2', 'pseudo-2', 'salt-2');

        $mapping = $this->lookup->findByPseudonym('pseudo-2');

        self::assertNotNull($mapping);
        self::assertSame('user-2', $mapping->subjectId);
        self::assertSame('pseudo-2', $mapping->pseudonym);
        self::assertSame('salt-2', $mapping->encryptedSalt);
    }

    #[Test]
    public function findByPseudonymReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->lookup->findByPseudonym('nonexistent'));
    }

    #[Test]
    public function deleteRemovesMapping(): void
    {
        $this->lookup->store('user-3', 'pseudo-3', 'salt-3');

        $deleted = $this->lookup->delete('user-3');

        self::assertTrue($deleted);
        self::assertNull($this->lookup->findBySubjectId('user-3'));
        self::assertNull($this->lookup->findByPseudonym('pseudo-3'));
    }

    #[Test]
    public function deleteReturnsFalseWhenNotFound(): void
    {
        self::assertFalse($this->lookup->delete('nonexistent'));
    }

    #[Test]
    public function storeOverwritesExistingMapping(): void
    {
        $this->lookup->store('user-4', 'pseudo-4a', 'salt-4a');
        $this->lookup->store('user-4', 'pseudo-4b', 'salt-4b');

        $mapping = $this->lookup->findBySubjectId('user-4');

        self::assertNotNull($mapping);
        self::assertSame('pseudo-4b', $mapping->pseudonym);
        self::assertSame('salt-4b', $mapping->encryptedSalt);
    }

    #[Test]
    public function multipleSubjectsAreStoredIndependently(): void
    {
        $this->lookup->store('user-a', 'pseudo-a', 'salt-a');
        $this->lookup->store('user-b', 'pseudo-b', 'salt-b');

        self::assertNotNull($this->lookup->findBySubjectId('user-a'));
        self::assertNotNull($this->lookup->findBySubjectId('user-b'));
        self::assertNotNull($this->lookup->findByPseudonym('pseudo-a'));
        self::assertNotNull($this->lookup->findByPseudonym('pseudo-b'));
    }

    #[Test]
    public function deletingOneSubjectDoesNotAffectOthers(): void
    {
        $this->lookup->store('user-x', 'pseudo-x', 'salt-x');
        $this->lookup->store('user-y', 'pseudo-y', 'salt-y');

        $this->lookup->delete('user-x');

        self::assertNull($this->lookup->findBySubjectId('user-x'));
        self::assertNotNull($this->lookup->findBySubjectId('user-y'));
    }

    #[Test]
    public function findBySubjectIdReturnsCreatedAtTimestamp(): void
    {
        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $this->lookup->store('user-ts', 'pseudo-ts', 'salt-ts');

        $mapping = $this->lookup->findBySubjectId('user-ts');
        $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        self::assertNotNull($mapping);
        self::assertGreaterThanOrEqual($before->getTimestamp(), $mapping->createdAt->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $mapping->createdAt->getTimestamp());
    }
}
