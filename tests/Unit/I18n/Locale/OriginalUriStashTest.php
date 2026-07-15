<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Locale;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Message\Uri;
use Pulsar\I18n\Locale\OriginalUriStash;

#[CoversClass(OriginalUriStash::class)]
final class OriginalUriStashTest extends TestCase
{
    #[Test]
    public function recordsTheCurrentUriWhenNoneStashedYet(): void
    {
        $request = new ServerRequest(method: 'GET', uri: new Uri(path: '/nl/coaching'));

        $remembered = OriginalUriStash::remember($request);

        $original = $remembered->getAttribute(OriginalUriStash::ATTRIBUTE);
        self::assertInstanceOf(Uri::class, $original);
        self::assertSame('/nl/coaching', $original->getPath());
    }

    #[Test]
    public function isSetOnceAndDoesNotOverwriteAnExistingRecord(): void
    {
        $first = new Uri(path: '/nl/coaching');
        $request = new ServerRequest(method: 'GET', uri: new Uri(path: '/coaching'))
            ->withAttribute(OriginalUriStash::ATTRIBUTE, $first);

        $remembered = OriginalUriStash::remember($request);

        self::assertSame($first, $remembered->getAttribute(OriginalUriStash::ATTRIBUTE));
    }

    #[Test]
    public function theAttributeNameIsStable(): void
    {
        self::assertSame('_original_uri', OriginalUriStash::ATTRIBUTE);
    }
}
