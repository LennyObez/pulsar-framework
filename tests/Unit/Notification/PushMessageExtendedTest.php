<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\PushMessage;

#[CoversClass(PushMessage::class)]
final class PushMessageExtendedTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $message = new PushMessage(
            title: 'New Order',
            body: 'Order #42 has been placed',
            data: ['order_id' => '42'],
            imageUrl: 'https://example.com/img.png',
            clickAction: 'https://example.com/orders/42',
        );

        self::assertSame('New Order', $message->title);
        self::assertSame('Order #42 has been placed', $message->body);
        self::assertSame(['order_id' => '42'], $message->data);
        self::assertSame('https://example.com/img.png', $message->imageUrl);
        self::assertSame('https://example.com/orders/42', $message->clickAction);
    }

    #[Test]
    public function defaultDataIsEmptyArray(): void
    {
        $message = new PushMessage(title: 'Title', body: 'Body');

        self::assertSame([], $message->data);
    }

    #[Test]
    public function defaultImageUrlIsNull(): void
    {
        $message = new PushMessage(title: 'Title', body: 'Body');

        self::assertNull($message->imageUrl);
    }

    #[Test]
    public function defaultClickActionIsNull(): void
    {
        $message = new PushMessage(title: 'Title', body: 'Body');

        self::assertNull($message->clickAction);
    }

    #[Test]
    public function emptyStringsAreValid(): void
    {
        $message = new PushMessage(title: '', body: '');

        self::assertSame('', $message->title);
        self::assertSame('', $message->body);
    }
}
