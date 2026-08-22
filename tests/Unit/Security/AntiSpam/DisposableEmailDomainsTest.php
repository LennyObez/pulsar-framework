<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\DisposableEmailDomains;

use function bin2hex;
use function file_put_contents;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(DisposableEmailDomains::class)]
final class DisposableEmailDomainsTest extends TestCase
{
    #[Test]
    public function matchesAnExactDomainCaseInsensitively(): void
    {
        $list = new DisposableEmailDomains(['mailinator.com', 'yopmail.com']);

        self::assertTrue($list->contains('mailinator.com'));
        self::assertTrue($list->contains('MailInator.COM'));
        self::assertTrue($list->contains('yopmail.com'));
    }

    #[Test]
    public function matchesSubdomainsViaTheirRegistrableParent(): void
    {
        $list = new DisposableEmailDomains(['mailinator.com']);

        self::assertTrue($list->contains('inbox.mailinator.com'));
        self::assertTrue($list->contains('a.b.mailinator.com'));
    }

    #[Test]
    public function doesNotMatchUnlistedDomainsOrBarePublicSuffixes(): void
    {
        $list = new DisposableEmailDomains(['mailinator.com']);

        self::assertFalse($list->contains('gmail.com'));
        self::assertFalse($list->contains('com'));
        self::assertFalse($list->contains('mailinator.org'));
        self::assertFalse($list->contains(''));
    }

    #[Test]
    public function ignoresBlankLinesAndComments(): void
    {
        $list = new DisposableEmailDomains(['# a comment', '', '  ', 'mailinator.com']);

        self::assertSame(1, $list->count());
        self::assertTrue($list->contains('mailinator.com'));
    }

    #[Test]
    public function parsesAListFileAndIgnoresMissingFiles(): void
    {
        $path = sys_get_temp_dir() . '/disp_' . bin2hex(random_bytes(6)) . '.txt';
        file_put_contents($path, "# header\nmailinator.com\n\nyopmail.com\n");

        $entries = DisposableEmailDomains::parseListFile($path);
        unlink($path);

        self::assertSame(['mailinator.com', 'yopmail.com'], $entries);
        self::assertSame([], DisposableEmailDomains::parseListFile($path . '.missing'));
    }
}
