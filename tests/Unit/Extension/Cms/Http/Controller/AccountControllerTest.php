<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Account\AccountSection;
use Pulsar\Extension\Cms\Account\AccountSectionProviderInterface;
use Pulsar\Extension\Cms\Account\AccountSectionRegistry;
use Pulsar\Extension\Cms\Commerce\Customer;
use Pulsar\Extension\Cms\Commerce\CustomerRepositoryInterface;
use Pulsar\Extension\Cms\Http\Controller\AccountController;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(AccountController::class)]
final class AccountControllerTest extends TestCase
{
    private CustomerRepositoryInterface&Stub $customerRepository;
    private AccountSectionRegistry $sectionRegistry;
    private AccountController $controller;

    protected function setUp(): void
    {
        $this->customerRepository = $this->createStub(CustomerRepositoryInterface::class);
        $this->sectionRegistry = new AccountSectionRegistry();
        $this->controller = new AccountController(
            $this->customerRepository,
            $this->sectionRegistry,
        );
    }

    #[Test]
    public function dashboardRequiresAuthentication(): void
    {
        $request = $this->createRequest();

        $this->expectException(AuthenticationException::class);

        $this->controller->dashboard($request);
    }

    #[Test]
    public function dashboardReturnsJsonWithCustomerData(): void
    {
        $customer = $this->createCustomer();
        $this->customerRepository->method('findByUserId')->willReturn($customer);

        $request = $this->createAuthenticatedRequest('user-1');

        $response = $this->controller->dashboard($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('customer', $body);
        /** @var array<string, mixed> $customer */
        $customer = $body['customer'];
        self::assertSame('test@example.com', $customer['email']);
        self::assertSame('dashboard', $body['active_section']);
    }

    #[Test]
    public function dashboardIncludesExtensionSections(): void
    {
        $customer = $this->createCustomer();
        $this->customerRepository->method('findByUserId')->willReturn($customer);

        $provider = $this->createStub(AccountSectionProviderInterface::class);
        $provider->method('getSections')->willReturn([
            new AccountSection('orders', 'Orders', 'shopping-bag', 10),
        ]);
        $this->sectionRegistry->register($provider);

        $request = $this->createAuthenticatedRequest('user-1');
        $response = $this->controller->dashboard($request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['sections']);
        self::assertCount(1, $body['sections']);
    }

    #[Test]
    public function profileReturnsProfileData(): void
    {
        $customer = $this->createCustomer();
        $this->customerRepository->method('findByUserId')->willReturn($customer);

        $request = $this->createAuthenticatedRequest('user-1');

        $response = $this->controller->profile($request);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('profile', $body['active_section']);
    }

    #[Test]
    public function settingsReturnsSettingsData(): void
    {
        $customer = $this->createCustomer();
        $this->customerRepository->method('findByUserId')->willReturn($customer);

        $request = $this->createAuthenticatedRequest('user-1');

        $response = $this->controller->settings($request);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('settings', $body['active_section']);
    }

    #[Test]
    public function sectionRendersExtensionContent(): void
    {
        $customer = $this->createCustomer();
        $this->customerRepository->method('findByUserId')->willReturn($customer);

        $provider = $this->createStub(AccountSectionProviderInterface::class);
        $provider->method('getSections')->willReturn([
            new AccountSection('orders', 'Orders', 'shopping-bag'),
        ]);
        $provider->method('renderFrontOffice')->willReturn('<table>Orders here</table>');
        $this->sectionRegistry->register($provider);

        $request = $this->createAuthenticatedRequest('user-1');

        $response = $this->controller->section($request, 'orders');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('orders', $body['active_section']);
        self::assertIsString($body['section_html']);
        self::assertStringContainsString('Orders here', $body['section_html']);
    }

    #[Test]
    public function sectionReturns404ForUnknownSection(): void
    {
        $customer = $this->createCustomer();
        $this->customerRepository->method('findByUserId')->willReturn($customer);

        $request = $this->createAuthenticatedRequest('user-1');

        $response = $this->controller->section($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function dashboardHandlesNullCustomer(): void
    {
        $this->customerRepository->method('findByUserId')->willReturn(null);

        $request = $this->createAuthenticatedRequest('user-1');
        $response = $this->controller->dashboard($request);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame([], $body['customer']);
    }

    private function createCustomer(): Customer
    {
        $now = new DateTimeImmutable();

        return new Customer(
            id: 'cust-1',
            tenantId: null,
            userId: 'user-1',
            email: 'test@example.com',
            displayName: 'Test User',
            billingAddress: ['line1' => '123 Main St', 'city' => 'Brussels', 'postalCode' => '1000', 'country' => 'BE'],
            shippingAddress: null,
            notes: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function createRequest(): ServerRequest
    {
        return new ServerRequest('GET', '/account');
    }

    private function createAuthenticatedRequest(string $userId): ServerRequest
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn($userId);
        $identity->method('isAuthenticated')->willReturn(true);

        $request = new ServerRequest('GET', '/account');

        return $request->withAttribute('identity', $identity);
    }
}
