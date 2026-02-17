<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection\Dsar;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\DataProtection\Dsar\DsarAttachment;
use Pulsar\DataProtection\Dsar\DsarDataSet;

#[CoversClass(DsarDataSet::class)]
final class DsarDataSetTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $records = [
            ['email' => 'user@example.com', 'name' => 'Alice'],
        ];
        $attachments = [
            new DsarAttachment('photo.jpg', 'img-data', 'image/jpeg'),
        ];

        $dataSet = new DsarDataSet(
            sourceName: 'auth',
            category: 'profile',
            records: $records,
            attachments: $attachments,
        );

        self::assertSame('auth', $dataSet->sourceName);
        self::assertSame('profile', $dataSet->category);
        self::assertCount(1, $dataSet->records);
        self::assertSame('user@example.com', $dataSet->records[0]['email']);
        self::assertCount(1, $dataSet->attachments);
        self::assertSame('photo.jpg', $dataSet->attachments[0]->filename);
    }

    #[Test]
    public function defaultAttachmentsIsEmptyArray(): void
    {
        $dataSet = new DsarDataSet(
            sourceName: 'orders',
            category: 'transactions',
            records: [['order_id' => '123']],
        );

        self::assertSame([], $dataSet->attachments);
    }

    #[Test]
    public function emptyFactoryCreatesDataSetWithNoRecordsOrAttachments(): void
    {
        $dataSet = DsarDataSet::empty('analytics', 'events');

        self::assertSame('analytics', $dataSet->sourceName);
        self::assertSame('events', $dataSet->category);
        self::assertSame([], $dataSet->records);
        self::assertSame([], $dataSet->attachments);
    }

    #[Test]
    public function multipleRecordsArePreserved(): void
    {
        $records = [
            ['id' => 1, 'action' => 'login'],
            ['id' => 2, 'action' => 'purchase'],
            ['id' => 3, 'action' => 'logout'],
        ];

        $dataSet = new DsarDataSet('activity', 'logs', $records);

        self::assertCount(3, $dataSet->records);
        self::assertSame('purchase', $dataSet->records[1]['action']);
    }

    #[Test]
    public function multipleAttachmentsArePreserved(): void
    {
        $attachments = [
            new DsarAttachment('doc1.pdf', 'data1', 'application/pdf'),
            new DsarAttachment('doc2.pdf', 'data2', 'application/pdf'),
        ];

        $dataSet = new DsarDataSet('documents', 'uploads', [], $attachments);

        self::assertCount(2, $dataSet->attachments);
        self::assertSame('doc1.pdf', $dataSet->attachments[0]->filename);
        self::assertSame('doc2.pdf', $dataSet->attachments[1]->filename);
    }

    #[Test]
    public function emptyRecordsWithAttachments(): void
    {
        $attachments = [
            new DsarAttachment('avatar.png', 'img', 'image/png'),
        ];

        $dataSet = new DsarDataSet('media', 'files', [], $attachments);

        self::assertSame([], $dataSet->records);
        self::assertCount(1, $dataSet->attachments);
    }
}
