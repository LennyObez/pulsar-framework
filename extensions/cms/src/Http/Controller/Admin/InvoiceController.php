<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Commerce\InvoiceServiceInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Http\Message\Response;
use RuntimeException;

/**
 * Admin controller for invoice viewing and downloading.
 *
 * Invoices are generated automatically on order confirmation.
 * This controller provides read-only access for administrative review.
 */
#[Internal(reason: 'CMS admin controller — implementation detail')]
final readonly class InvoiceController
{
    public function __construct(
        private InvoiceServiceInterface $invoiceService,
        private GateInterface $gate,
    ) {}

    public function show(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.commerce.orders.view');

        try {
            $html = $this->invoiceService->getInvoiceHtml($id);

            return Response::html($html);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        }
    }

    public function download(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.commerce.orders.view');

        try {
            $html = $this->invoiceService->getInvoiceHtml($id);

            return new Response(
                statusCode: 200,
                headers: [
                    'Content-Type' => 'text/html; charset=utf-8',
                    'Content-Disposition' => "attachment; filename=\"invoice-{$id}.html\"",
                ],
                body: $html,
            );
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        }
    }

    private function requireIdentity(ServerRequestInterface $request): IdentityInterface
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        if ($identity === null || !$identity->isAuthenticated()) {
            throw new RuntimeException('Authentication required');
        }

        return $identity;
    }

    private function authorize(IdentityInterface $identity, string $permission): void
    {
        if ($this->gate->denies($identity, $permission)) {
            throw new RuntimeException('Permission denied');
        }
    }
}
