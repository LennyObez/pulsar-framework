<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\NotificationsDirective;

use function str_contains;

#[CoversClass(NotificationsDirective::class)]
final class NotificationsDirectiveTest extends TestCase
{
    #[Test]
    public function nameReturnsNotifications(): void
    {
        self::assertSame('notifications', new NotificationsDirective()->name());
    }

    #[Test]
    public function compileSubstitutesExpressionIntoUserIdAssignment(): void
    {
        // Regression: the directive body used a nowdoc (<<<'PHP'), so the
        // literal token "$expression" was emitted into the compiled template
        // instead of the caller's expression. The compiled output must assign
        // the actual expression to $__notifUserId.
        $output = new NotificationsDirective()->compile('$userId');

        self::assertTrue(str_contains($output, '$__notifUserId = $userId;'));
        self::assertFalse(
            str_contains($output, '$__notifUserId = $expression;'),
            'Compiled output must not contain the un-interpolated $expression placeholder.',
        );
    }

    #[Test]
    public function compileWithoutArgumentDefaultsToNull(): void
    {
        $output = new NotificationsDirective()->compile('');

        self::assertTrue(str_contains($output, '$__notifUserId = null;'));
    }

    #[Test]
    public function compiledOutputOpensWithPhpAssignment(): void
    {
        // The compiled directive is concatenated from a sprintf-built prefix
        // and a nowdoc body. The prefix must open a PHP block and assign the
        // resolved user id before the nowdoc body consumes $__notifUserId.
        $output = new NotificationsDirective()->compile('$userId');

        self::assertStringStartsWith("<?php\n\$__notifUserId = \$userId;", $output);
        self::assertTrue(str_contains($output, '$__notifRepo = $__notifRepo ?? null;'));
    }
}
