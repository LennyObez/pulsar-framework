<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\Account\AccountSectionRegistry;
use Pulsar\Extension\Cms\Commerce\Customer;
use Pulsar\Extension\Cms\Commerce\CustomerRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\OrderRepositoryInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function count;
use function in_array;
use function is_int;
use function is_string;
use function max;
use function min;

/**
 * Admin controller for customer account management.
 *
 * Provides WordPress-style customer management:
 * - Customer list with search, filters, and stats
 * - Customer detail view with data from all active extensions
 * - Admin notes on customer accounts
 *
 * When extensions like Forum or Payments are active, their
 * AccountSectionProviders contribute tabs to the customer detail view.
 */
#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class CustomerController extends AbstractAdminController
{
    public function __construct(
        private CustomerRepositoryInterface $customerRepository,
        private AccountSectionRegistry $sectionRegistry,
        private ?OrderRepositoryInterface $orderRepository = null,
        private ?AuditLoggerInterface $auditLogger = null,
        ?GateInterface $gate = null,
        ?TemplateEngineInterface $templateEngine = null,
    ) {
        parent::__construct($templateEngine, $gate);
    }

    /**
     * List all customers with search, role filter, and aggregate stats.
     *
     * GET /admin/customers
     */
    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.customers.view');

        $params = $request->getQueryParams();
        /** @var mixed $rawPage */
        $rawPage = $params['page'] ?? null;
        $page = max(1, is_int($rawPage) ? $rawPage : 1);
        /** @var mixed $rawPerPage */
        $rawPerPage = $params['per_page'] ?? null;
        $perPage = min(100, max(1, is_int($rawPerPage) ? $rawPerPage : 20));
        /** @var mixed $rawSearch */
        $rawSearch = $params['search'] ?? null;
        $search = is_string($rawSearch) ? $rawSearch : null;

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $result = $this->customerRepository->listCustomers(
            tenantId: $tenantId,
            search: $search,
            page: $page,
            perPage: $perPage,
        );

        $data = [
            'customers' => array_map(fn(Customer $c) => [
                'id' => $c->id,
                'email' => $c->email,
                'display_name' => $c->displayName ?? $c->email,
                'has_linked_user' => $c->hasLinkedUser(),
                'created_at' => $c->createdAt->format('c'),
                'updated_at' => $c->updatedAt->format('c'),
            ], $result->items),
            'pagination' => $result->metaToArray(),
            'search' => $search,
        ];

        return $this->respondWithView($request, 'admin.customers.index', $data);
    }

    /**
     * Show detailed customer view with all extension sections.
     *
     * GET /admin/customers/{id}
     */
    public function show(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.customers.view');

        $customer = $this->customerRepository->findById($id);

        if ($customer === null) {
            return Response::json(['error' => 'Customer not found'], 404);
        }

        // Get the active section tab (default: overview)
        $params = $request->getQueryParams();
        /** @var mixed $rawSection */
        $rawSection = $params['section'] ?? null;
        $activeSection = is_string($rawSection) ? $rawSection : 'overview';

        // Collect sections from all extensions
        $userId = $customer->userId ?? $customer->id;
        $sections = $this->sectionRegistry->getSections($userId);

        // Render the active extension section if not a built-in tab
        $sectionHtml = '';
        $builtInSections = ['overview', 'notes'];

        if (!in_array($activeSection, $builtInSections, true)) {
            /** @var array<string, mixed> $sectionParams */
            $sectionParams = $params;

            $sectionHtml = $this->sectionRegistry->renderBackOffice(
                $activeSection,
                $userId,
                $sectionParams,
            );
        }

        // Aggregate stats
        $stats = $this->buildCustomerStats($customer);

        $data = [
            'customer' => [
                'id' => $customer->id,
                'tenant_id' => $customer->tenantId,
                'user_id' => $customer->userId,
                'email' => $customer->email,
                'display_name' => $customer->displayName ?? $customer->email,
                'billing_address' => $customer->billingAddress,
                'shipping_address' => $customer->shippingAddress,
                'created_at' => $customer->createdAt->format('c'),
                'updated_at' => $customer->updatedAt->format('c'),
            ],
            'stats' => $stats,
            'sections' => $sections,
            'active_section' => $activeSection,
            'section_html' => $sectionHtml,
        ];

        $this->auditLogger?->log(
            AuditEvent::DataAccess,
            AuditOutcome::Success,
            $identity->id(),
            'cms.customer.viewed',
            "customer:$id",
            ['customer_email' => $customer->email],
        );

        return $this->respondWithView($request, 'admin.customers.show', $data);
    }

    /**
     * Add an admin note to a customer account.
     *
     * POST /admin/customers/{id}/notes
     */
    public function addNote(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.customers.manage');

        $customer = $this->customerRepository->findById($id);

        if ($customer === null) {
            return Response::json(['error' => 'Customer not found'], 404);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        /** @var mixed $rawNote */
        $rawNote = $body['note'] ?? null;
        $note = is_string($rawNote) ? $rawNote : '';

        if ($note === '') {
            return Response::json(['error' => 'Note content is required'], 400);
        }

        $existingNotes = $customer->notes ?? '';
        $timestamp = new DateTimeImmutable()->format('Y-m-d H:i');
        $authorName = $identity->id();
        $newNotes = $existingNotes . "\n[$timestamp] ($authorName) $note";

        $updated = clone($customer, [
            'notes' => trim($newNotes),
            'updatedAt' => new DateTimeImmutable(),
        ]);

        $this->customerRepository->save($updated);

        $this->auditLogger?->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            $identity->id(),
            'cms.customer.note_added',
            "customer:$id",
        );

        return Response::redirect("/admin/customers/$id?section=notes");
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCustomerStats(Customer $customer): array
    {
        $stats = [
            'member_since' => $customer->createdAt->format('M j, Y'),
        ];

        if ($this->orderRepository !== null) {
            $orders = $this->orderRepository->findByCustomer($customer->id);
            $totalSpent = 0;

            foreach ($orders as $order) {
                $totalSpent += $order->total;
            }

            $stats['total_orders'] = count($orders);
            $stats['total_spent'] = $totalSpent;
            $stats['currency'] = $orders !== [] ? $orders[0]->currency : 'EUR';
        }

        return $stats;
    }
}
