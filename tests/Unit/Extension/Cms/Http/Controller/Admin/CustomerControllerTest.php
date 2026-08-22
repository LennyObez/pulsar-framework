<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationLinks;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Account\AccountSection;
use Pulsar\Extension\Cms\Account\AccountSectionProviderInterface;
use Pulsar\Extension\Cms\Account\AccountSectionRegistry;
use Pulsar\Extension\Cms\Commerce\Customer;
use Pulsar\Extension\Cms\Commerce\CustomerRepositoryInterface;
use Pulsar\Extension\Cms\Http\Controller\Admin\CustomerController;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(CustomerController::class)]
final class CustomerControllerTest extends TestCase
{
    private CustomerRepositoryInterface&Stub $customerRepository;
    private AccountSectionRegistry $sectionRegistry;
    private CustomerController $controller;

    protected function setUp(): void
    {
        $this->customerRepository = $this->createStub(CustomerRepositoryInterface::class);
        $this->sectionRegistry = new AccountSectionRegistry();
        $this->controller = new CustomerController(
            $this->customerRepository,
            $this->sectionRegistry,
        );
    }

    #[Test]
    public function indexRequiresAuthentication(): void
    {
        $request = new ServerRequest('GET', '/admin/cms/customers');

        $this->expectException(AuthenticationException::class);

        $this->controller->index($request);
    }

    #[Test]
    public function indexListsCustomers(): void
    {
        $customer = $this->createCustomer('cust-1', 'alice@example.com', 'Alice');
        $pagination = new PaginationResult(
            items: [$customer],
            total: 1,
            hasMore: false,
            perPage: 20,
            currentPage: 1,
            lastPage: 1,
            links: new PaginationLinks(),
        );

        $this->customerRepository->method('listCustomers')->willReturn($pagination);

        $request = $this->createAuthenticatedAdminRequest('GET', '/admin/cms/customers');

        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['customers']);
        self::assertCount(1, $body['customers']);
        /** @var list<array<string, mixed>> $customers */
        $customers = $body['customers'];
        self::assertSame('alice@example.com', $customers[0]['email']);
        self::assertSame('Alice', $customers[0]['display_name']);
    }

    #[Test]
    public function showReturnsCustomerWithSections(): void
    {
        $customer = $this->createCustomer('cust-1', 'bob@example.com', 'Bob');
        $this->customerRepository->method('findById')->willReturn($customer);

        $provider = $this->createStub(AccountSectionProviderInterface::class);
        $provider->method('getSections')->willReturn([
            new AccountSection('orders', 'Orders', 'shopping-bag', 10),
        ]);
        $this->sectionRegistry->register($provider);

        $request = $this->createAuthenticatedAdminRequest('GET', '/admin/cms/customers/cust-1');

        $response = $this->controller->show($request, 'cust-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        /** @var array<string, mixed> $customer */
        $customer = $body['customer'];
        self::assertSame('bob@example.com', $customer['email']);
        self::assertSame('overview', $body['active_section']);
        self::assertIsArray($body['sections']);
        self::assertCount(1, $body['sections']);
    }

    #[Test]
    public function showReturns404ForMissingCustomer(): void
    {
        $this->customerRepository->method('findById')->willReturn(null);

        $request = $this->createAuthenticatedAdminRequest('GET', '/admin/cms/customers/nonexistent');

        $response = $this->controller->show($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function showRendersExtensionSectionWhenRequested(): void
    {
        $customer = $this->createCustomer('cust-1', 'carol@example.com', 'Carol');
        $this->customerRepository->method('findById')->willReturn($customer);

        $provider = $this->createStub(AccountSectionProviderInterface::class);
        $provider->method('getSections')->willReturn([
            new AccountSection('forum-activity', 'Forum Activity', 'message-circle'),
        ]);
        $provider->method('renderBackOffice')->willReturn('<div>Posts list</div>');
        $this->sectionRegistry->register($provider);

        $request = $this->createAuthenticatedAdminRequest('GET', '/admin/cms/customers/cust-1');
        $request = $request->withQueryParams(['section' => 'forum-activity']);

        $response = $this->controller->show($request, 'cust-1');

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('forum-activity', $body['active_section']);
        self::assertIsString($body['section_html']);
        self::assertStringContainsString('Posts list', $body['section_html']);
    }

    #[Test]
    public function addNoteRequiresNonEmptyContent(): void
    {
        $customer = $this->createCustomer('cust-1', 'dan@example.com', 'Dan');
        $this->customerRepository->method('findById')->willReturn($customer);

        $request = $this->createAuthenticatedAdminRequest('POST', '/admin/cms/customers/cust-1/notes');
        $request = $request->withParsedBody(['note' => '']);

        $response = $this->controller->addNote($request, 'cust-1');

        self::assertSame(400, $response->getStatusCode());
    }

    private function createCustomer(string $id, string $email, string $name): Customer
    {
        $now = new DateTimeImmutable();

        return new Customer(
            id: $id,
            tenantId: null,
            userId: "user-$id",
            email: $email,
            displayName: $name,
            billingAddress: null,
            shippingAddress: null,
            notes: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function createAuthenticatedAdminRequest(string $method, string $uri): ServerRequest
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn('admin-1');
        $identity->method('isAuthenticated')->willReturn(true);

        $request = new ServerRequest($method, $uri);

        return $request->withAttribute('identity', $identity);
    }
}
