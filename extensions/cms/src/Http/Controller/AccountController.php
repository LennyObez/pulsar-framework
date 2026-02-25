<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Account\AccountSectionRegistry;
use Pulsar\Extension\Cms\Commerce\Customer;
use Pulsar\Extension\Cms\Commerce\CustomerRepositoryInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function is_array;
use function is_string;

/**
 * Front-office customer account controller.
 *
 * Renders the customer-facing account pages at /account/*.
 * Collects sections from all active extensions (Forum, Payments)
 * via AccountSectionRegistry and renders them as tabs.
 */
#[Internal(reason: 'CMS front-office controller; implementation detail')]
final readonly class AccountController
{
    public function __construct(
        private CustomerRepositoryInterface $customerRepository,
        private AccountSectionRegistry $sectionRegistry,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    /**
     * Account dashboard: overview with recent activity from all extensions.
     *
     * GET /account
     */
    public function dashboard(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $customer = $this->resolveCustomer($identity);

        $sections = $this->sectionRegistry->getSections($identity->id());

        $data = [
            'customer' => $this->customerToArray($customer),
            'sections' => $sections,
            'active_section' => 'dashboard',
        ];

        return $this->respond($request, 'account.dashboard', $data);
    }

    /**
     * Customer profile management: name, email, avatar, addresses.
     *
     * GET /account/profile
     */
    public function profile(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $customer = $this->resolveCustomer($identity);

        $sections = $this->sectionRegistry->getSections($identity->id());

        $data = [
            'customer' => $this->customerToArray($customer),
            'sections' => $sections,
            'active_section' => 'profile',
            'saved' => isset($request->getQueryParams()['saved']),
        ];

        return $this->respond($request, 'account.profile', $data);
    }

    /**
     * Update customer profile.
     *
     * POST /account/profile
     */
    public function updateProfile(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $customer = $this->resolveCustomer($identity);

        if ($customer === null) {
            return Response::json(['error' => 'Customer profile not found'], 404);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $displayName = is_string($body['display_name'] ?? null) ? $body['display_name'] : $customer->displayName;

        /** @var array<string, mixed>|null $billingAddress */
        $billingAddress = isset($body['billing_address']) && is_array($body['billing_address'])
            ? $body['billing_address']
            : $customer->billingAddress;

        /** @var array<string, mixed>|null $shippingAddress */
        $shippingAddress = isset($body['shipping_address']) && is_array($body['shipping_address'])
            ? $body['shipping_address']
            : $customer->shippingAddress;

        $updated = clone($customer, [
            'displayName' => $displayName,
            'billingAddress' => $billingAddress,
            'shippingAddress' => $shippingAddress,
            'updatedAt' => new DateTimeImmutable(),
        ]);

        $this->customerRepository->save($updated);

        return Response::redirect('/account/profile?saved=1');
    }

    /**
     * Account settings: password, 2FA, notification preferences.
     *
     * GET /account/settings
     */
    public function settings(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $customer = $this->resolveCustomer($identity);

        $sections = $this->sectionRegistry->getSections($identity->id());

        $data = [
            'customer' => $this->customerToArray($customer),
            'sections' => $sections,
            'active_section' => 'settings',
        ];

        return $this->respond($request, 'account.settings', $data);
    }

    /**
     * Render a dynamic section provided by an extension (Forum, Payments, etc.).
     *
     * GET /account/section/{sectionId}
     */
    public function section(ServerRequestInterface $request, string $sectionId): Response
    {
        $identity = $this->requireIdentity($request);
        $customer = $this->resolveCustomer($identity);

        $sections = $this->sectionRegistry->getSections($identity->id());

        /** @var array<string, mixed> $queryParams */
        $queryParams = $request->getQueryParams();

        $sectionHtml = $this->sectionRegistry->renderFrontOffice(
            $sectionId,
            $identity->id(),
            $queryParams,
        );

        if ($sectionHtml === '') {
            return Response::json(['error' => 'Section not found'], 404);
        }

        $data = [
            'customer' => $this->customerToArray($customer),
            'sections' => $sections,
            'active_section' => $sectionId,
            'section_html' => $sectionHtml,
        ];

        return $this->respond($request, 'account.section', $data);
    }

    private function requireIdentity(ServerRequestInterface $request): IdentityInterface
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        if ($identity === null || !$identity->isAuthenticated()) {
            throw AuthenticationException::invalidCredentials();
        }

        return $identity;
    }

    private function resolveCustomer(IdentityInterface $identity): ?Customer
    {
        return $this->customerRepository->findByUserId($identity->id());
    }

    /**
     * @return array<string, mixed>
     */
    private function customerToArray(?Customer $customer): array
    {
        if ($customer === null) {
            return [];
        }

        return [
            'id' => $customer->id,
            'email' => $customer->email,
            'display_name' => $customer->displayName,
            'billing_address' => $customer->billingAddress,
            'shipping_address' => $customer->shippingAddress,
            'created_at' => $customer->createdAt->format('c'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function respond(ServerRequestInterface $request, string $template, array $data, int $status = 200): Response
    {
        $accept = $request->getHeaderLine('Accept');

        if ($accept === 'application/json' || str_contains($accept, 'application/json')) {
            return Response::json($data, $status);
        }

        if ($this->templateEngine === null) {
            return Response::json($data, $status);
        }

        $html = $this->templateEngine->render($template, $data);

        return Response::html($html, $status);
    }
}
