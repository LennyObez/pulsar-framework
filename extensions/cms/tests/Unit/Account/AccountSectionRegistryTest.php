<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Account;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Account\AccountSection;
use Pulsar\Extension\Cms\Account\AccountSectionProviderInterface;
use Pulsar\Extension\Cms\Account\AccountSectionRegistry;

#[CoversClass(AccountSectionRegistry::class)]
final class AccountSectionRegistryTest extends TestCase
{
    #[Test]
    public function hasProvidersReturnsFalseWhenEmpty(): void
    {
        $registry = new AccountSectionRegistry();

        self::assertFalse($registry->hasProviders());
    }

    #[Test]
    public function hasProvidersReturnsTrueAfterRegister(): void
    {
        $registry = new AccountSectionRegistry();
        $provider = $this->createStub(AccountSectionProviderInterface::class);
        $provider->method('getSections')->willReturn([]);

        $registry->register($provider);

        self::assertTrue($registry->hasProviders());
    }

    #[Test]
    public function getSectionsSortsByPriority(): void
    {
        $lowPriority = new AccountSection('orders', 'Orders', 'bag', 100);
        $highPriority = new AccountSection('profile', 'Profile', 'user', 10);

        $provider1 = $this->createStub(AccountSectionProviderInterface::class);
        $provider1->method('getSections')->willReturn([$lowPriority]);

        $provider2 = $this->createStub(AccountSectionProviderInterface::class);
        $provider2->method('getSections')->willReturn([$highPriority]);

        $registry = new AccountSectionRegistry();
        $registry->register($provider1);
        $registry->register($provider2);

        $sections = $registry->getSections('user-1');

        self::assertCount(2, $sections);
        self::assertSame('profile', $sections[0]->id);
        self::assertSame('orders', $sections[1]->id);
    }

    #[Test]
    public function renderFrontOfficeDelegatesToMatchingProvider(): void
    {
        $section = new AccountSection('orders', 'Orders', 'bag');

        $provider = $this->createStub(AccountSectionProviderInterface::class);
        $provider->method('getSections')->willReturn([$section]);
        $provider->method('renderFrontOffice')->willReturn('<div>Orders content</div>');

        $registry = new AccountSectionRegistry();
        $registry->register($provider);

        $html = $registry->renderFrontOffice('orders', 'user-1');

        self::assertSame('<div>Orders content</div>', $html);
    }

    #[Test]
    public function renderFrontOfficeReturnsEmptyForUnknownSection(): void
    {
        $registry = new AccountSectionRegistry();

        self::assertSame('', $registry->renderFrontOffice('unknown', 'user-1'));
    }

    #[Test]
    public function renderBackOfficeDelegatesToMatchingProvider(): void
    {
        $section = new AccountSection('forum', 'Forum', 'message');

        $provider = $this->createStub(AccountSectionProviderInterface::class);
        $provider->method('getSections')->willReturn([$section]);
        $provider->method('renderBackOffice')->willReturn('<div>Admin view</div>');

        $registry = new AccountSectionRegistry();
        $registry->register($provider);

        $html = $registry->renderBackOffice('forum', 'user-1');

        self::assertSame('<div>Admin view</div>', $html);
    }

    #[Test]
    public function renderBackOfficeReturnsEmptyForUnknownSection(): void
    {
        $registry = new AccountSectionRegistry();

        self::assertSame('', $registry->renderBackOffice('unknown', 'user-1'));
    }
}
