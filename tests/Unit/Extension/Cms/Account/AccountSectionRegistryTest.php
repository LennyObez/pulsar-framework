<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Account;

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
    public function emptyRegistryReturnsNoSections(): void
    {
        $registry = new AccountSectionRegistry();

        $sections = $registry->getSections('user-1');

        self::assertSame([], $sections);
        self::assertFalse($registry->hasProviders());
    }

    #[Test]
    public function registeredProviderContributesSections(): void
    {
        $registry = new AccountSectionRegistry();
        $provider = $this->createProvider([
            new AccountSection('orders', 'Orders', 'shopping-bag', 10),
            new AccountSection('invoices', 'Invoices', 'file-text', 20),
        ]);

        $registry->register($provider);

        $sections = $registry->getSections('user-1');

        self::assertCount(2, $sections);
        self::assertSame('orders', $sections[0]->id);
        self::assertSame('invoices', $sections[1]->id);
        self::assertTrue($registry->hasProviders());
    }

    #[Test]
    public function multipleProvidersSectionsMergedAndSortedByPriority(): void
    {
        $registry = new AccountSectionRegistry();

        $forumProvider = $this->createProvider([
            new AccountSection('forum-activity', 'Forum Activity', 'message-circle', 40),
            new AccountSection('badges', 'Badges', 'award', 45),
        ]);

        $paymentsProvider = $this->createProvider([
            new AccountSection('orders', 'Orders', 'shopping-bag', 10),
            new AccountSection('invoices', 'Invoices', 'file-text', 20),
        ]);

        $registry->register($forumProvider);
        $registry->register($paymentsProvider);

        $sections = $registry->getSections('user-1');

        self::assertCount(4, $sections);
        self::assertSame('orders', $sections[0]->id);
        self::assertSame('invoices', $sections[1]->id);
        self::assertSame('forum-activity', $sections[2]->id);
        self::assertSame('badges', $sections[3]->id);
    }

    #[Test]
    public function renderFrontOfficeDelegatesToCorrectProvider(): void
    {
        $registry = new AccountSectionRegistry();

        $provider = $this->createStub(AccountSectionProviderInterface::class);
        $provider->method('getSections')->willReturn([
            new AccountSection('orders', 'Orders', 'shopping-bag'),
        ]);
        $provider->method('renderFrontOffice')->willReturn('<div>Order list</div>');

        $registry->register($provider);

        $html = $registry->renderFrontOffice('orders', 'user-1');

        self::assertSame('<div>Order list</div>', $html);
    }

    #[Test]
    public function renderFrontOfficeReturnsEmptyForUnknownSection(): void
    {
        $registry = new AccountSectionRegistry();

        $html = $registry->renderFrontOffice('nonexistent', 'user-1');

        self::assertSame('', $html);
    }

    #[Test]
    public function renderBackOfficeDelegatesToCorrectProvider(): void
    {
        $registry = new AccountSectionRegistry();

        $provider = $this->createStub(AccountSectionProviderInterface::class);
        $provider->method('getSections')->willReturn([
            new AccountSection('badges', 'Badges', 'award'),
        ]);
        $provider->method('renderBackOffice')->willReturn('<div>Admin badge view</div>');

        $registry->register($provider);

        $html = $registry->renderBackOffice('badges', 'user-1');

        self::assertSame('<div>Admin badge view</div>', $html);
    }

    #[Test]
    public function sectionBadgeCountIsPreserved(): void
    {
        $registry = new AccountSectionRegistry();
        $provider = $this->createProvider([
            new AccountSection('orders', 'Orders', 'shopping-bag', 10, '3'),
        ]);

        $registry->register($provider);

        $sections = $registry->getSections('user-1');

        self::assertSame('3', $sections[0]->badgeCount);
    }

    #[Test]
    public function sameProviderNotDuplicated(): void
    {
        $registry = new AccountSectionRegistry();
        $provider = $this->createProvider([
            new AccountSection('orders', 'Orders', 'shopping-bag'),
        ]);

        $registry->register($provider);
        $registry->register($provider);

        $sections = $registry->getSections('user-1');

        self::assertCount(2, $sections);
    }

    /**
     * @param list<AccountSection> $sections
     */
    private function createProvider(array $sections): AccountSectionProviderInterface
    {
        $provider = $this->createStub(AccountSectionProviderInterface::class);
        $provider->method('getSections')->willReturn($sections);

        return $provider;
    }
}
