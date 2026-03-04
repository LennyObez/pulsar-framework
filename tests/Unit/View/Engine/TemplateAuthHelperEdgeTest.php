<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\View\Engine\TemplateAuthHelper;

/**
 * Edge case coverage for TemplateAuthHelper.
 */
#[CoversClass(TemplateAuthHelper::class)]
final class TemplateAuthHelperEdgeTest extends TestCase
{
    #[Test]
    public function canReturnsFalseWhenGateIsNull(): void
    {
        $helper = new TemplateAuthHelper(null, null);

        self::assertFalse($helper->can('edit'));
    }

    #[Test]
    public function canReturnsFalseWhenIdentityIsNull(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $helper = new TemplateAuthHelper($gate, null);

        self::assertFalse($helper->can('edit'));
    }

    #[Test]
    public function canDelegatesToGateWhenBothPresent(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $identity = $this->createStub(IdentityInterface::class);
        $gate->method('allows')->willReturn(true);

        $helper = new TemplateAuthHelper($gate, $identity);

        self::assertTrue($helper->can('edit'));
    }

    #[Test]
    public function canReturnsFalseWhenGateDenies(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $identity = $this->createStub(IdentityInterface::class);
        $gate->method('allows')->willReturn(false);

        $helper = new TemplateAuthHelper($gate, $identity);

        self::assertFalse($helper->can('admin'));
    }

    #[Test]
    public function authenticatedReturnsTrueForAuthenticatedIdentity(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);

        $helper = new TemplateAuthHelper(null, $identity);

        self::assertTrue($helper->authenticated());
        self::assertFalse($helper->guest());
    }

    #[Test]
    public function authenticatedReturnsFalseForUnauthenticatedIdentity(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(false);

        $helper = new TemplateAuthHelper(null, $identity);

        self::assertFalse($helper->authenticated());
        self::assertTrue($helper->guest());
    }

    #[Test]
    public function guestReturnsTrueWhenIdentityIsNull(): void
    {
        $helper = new TemplateAuthHelper(null, null);

        self::assertTrue($helper->guest());
        self::assertFalse($helper->authenticated());
    }
}
