<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\View\Engine\TemplateAuthHelper;

#[CoversClass(TemplateAuthHelper::class)]
final class TemplateAuthHelperTest extends TestCase
{
    #[Test]
    public function canReturnsFalseWithoutGate(): void
    {
        $helper = new TemplateAuthHelper(null, $this->createStub(IdentityInterface::class));

        self::assertFalse($helper->can('edit-post'));
    }

    #[Test]
    public function canReturnsFalseWithoutIdentity(): void
    {
        $helper = new TemplateAuthHelper($this->createStub(GateInterface::class), null);

        self::assertFalse($helper->can('edit-post'));
    }

    #[Test]
    public function canDelegatesToGate(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $gate = $this->createStub(GateInterface::class);
        $gate->method('allows')->willReturn(true);

        $helper = new TemplateAuthHelper($gate, $identity);

        self::assertTrue($helper->can('manage-users'));
    }

    #[Test]
    public function authenticatedReturnsTrueWhenIdentityIsAuthenticated(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);

        $helper = new TemplateAuthHelper(null, $identity);

        self::assertTrue($helper->authenticated());
    }

    #[Test]
    public function authenticatedReturnsFalseWithoutIdentity(): void
    {
        $helper = new TemplateAuthHelper(null, null);

        self::assertFalse($helper->authenticated());
    }

    #[Test]
    public function authenticatedReturnsFalseWhenNotAuthenticated(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(false);

        $helper = new TemplateAuthHelper(null, $identity);

        self::assertFalse($helper->authenticated());
    }

    #[Test]
    public function guestIsInverseOfAuthenticated(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);

        $helper = new TemplateAuthHelper(null, $identity);

        self::assertFalse($helper->guest());
    }

    #[Test]
    public function guestReturnsTrueWhenNotAuthenticated(): void
    {
        $helper = new TemplateAuthHelper(null, null);

        self::assertTrue($helper->guest());
    }
}
