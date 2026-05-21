<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\Commerce\InvoiceServiceInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

/**
 * Admin controller for invoice viewing and downloading.
 *
 * Invoices are generated automatically on order confirmation.
 * This controller provides read-only access for administrative review.
 */
#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class InvoiceController extends AbstractAdminController
{
    public function __construct(
        private InvoiceServiceInterface $invoiceService,
        ?GateInterface $gate = null,
        ?TemplateEngineInterface $templateEngine = null,
    ) {
        parent::__construct($templateEngine, $gate);
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

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
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function download(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.commerce.orders.view');

        try {
            $html = $this->invoiceService->getInvoiceHtml($id);

            return new Response(
                headers: [
                    'Content-Type' => 'text/html; charset=utf-8',
                    'Content-Disposition' => "attachment; filename=\"invoice-$id.html\"",
                ],
                body: $html,
            );
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        }
    }

}
