<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\Content\Redirect;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Seo\RedirectManagerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function count;
use function in_array;
use function is_int;
use function is_string;
use function max;
use function min;
use function sprintf;

/**
 * Admin controller for URL redirect management.
 *
 * Provides CRUD operations for redirects: listing with pagination,
 * creation, deletion, bulk CSV import with dry-run, and CSV export.
 */
#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class RedirectController extends AbstractAdminController
{
    public function __construct(
        private RedirectManagerInterface $redirectManager,
        ?GateInterface $gate = null,
        ?TemplateEngineInterface $templateEngine = null,
    ) {
        parent::__construct($templateEngine, $gate);
    }

    /**
     * List all redirects with pagination.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.seo.view');

        $params = $request->getQueryParams();
        /** @var mixed $rawPage */
        $rawPage = $params['page'] ?? null;
        $page = max(1, is_int($rawPage) ? $rawPage : 1);
        /** @var mixed $rawPerPage */
        $rawPerPage = $params['per_page'] ?? null;
        $perPage = min(100, max(1, is_int($rawPerPage) ? $rawPerPage : 50));

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $redirects = $this->redirectManager->listAll($page, $perPage, $tenantId);

        $data = [
            'redirects' => array_map(static fn(Redirect $r) => [
                'id' => $r->id,
                'from_path' => $r->fromPath,
                'to_path' => $r->toPath,
                'status_code' => $r->statusCode,
                'locale' => $r->locale,
                'hits' => $r->hits,
                'last_hit_at' => $r->lastHitAt?->format('c'),
                'created_at' => $r->createdAt->format('c'),
                'created_by' => $r->createdBy,
                'reason' => $r->reason,
            ], $redirects),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
            ],
        ];

        return $this->respondWithView($request, 'admin.seo.redirects', $data);
    }

    /**
     * Create a new redirect.
     */
    public function create(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.seo.manage');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawFromPath */
        $rawFromPath = $body['from_path'] ?? null;
        $fromPath = is_string($rawFromPath) ? $rawFromPath : '';
        /** @var mixed $rawToPath */
        $rawToPath = $body['to_path'] ?? null;
        $toPath = is_string($rawToPath) ? $rawToPath : '';
        /** @var mixed $rawStatusCode */
        $rawStatusCode = $body['status_code'] ?? null;
        $statusCode = is_int($rawStatusCode) ? $rawStatusCode : 301;
        /** @var mixed $rawLocale */
        $rawLocale = $body['locale'] ?? null;
        $locale = is_string($rawLocale) && $rawLocale !== '' ? $rawLocale : null;
        /** @var mixed $rawReason */
        $rawReason = $body['reason'] ?? null;
        $reason = is_string($rawReason) ? $rawReason : 'Created via admin';

        if ($fromPath === '' || $toPath === '') {
            return Response::json(['error' => 'Both from_path and to_path are required'], 400);
        }

        if (!in_array($statusCode, [301, 308], true)) {
            return Response::json(['error' => 'Status code must be 301 or 308'], 400);
        }

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        try {
            $redirect = $this->redirectManager->create(
                fromPath: $fromPath,
                toPath: $toPath,
                statusCode: $statusCode,
                createdBy: $identity->id(),
                reason: $reason,
                locale: $locale,
                tenantId: $tenantId,
            );

            return Response::json([
                'id' => $redirect->id,
                'from_path' => $redirect->fromPath,
                'to_path' => $redirect->toPath,
                'status_code' => $redirect->statusCode,
            ], 201);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Delete a redirect by ID.
     */
    public function delete(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.seo.manage');

        try {
            $this->redirectManager->delete($id);

            return Response::json(['id' => $id, 'status' => 'deleted']);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Bulk import redirects from a CSV file upload.
     *
     * Supports dry_run mode to preview import results without persisting.
     */
    public function bulkImport(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.seo.manage');

        $uploadedFiles = $request->getUploadedFiles();

        /** @var UploadedFileInterface|null $file */
        $file = $uploadedFiles['file'] ?? null;

        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return Response::json(['error' => 'No valid CSV file uploaded'], 400);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $dryRun = ($body['dry_run'] ?? '0') === '1' || ($body['dry_run'] ?? false) === true;

        $csvContent = (string) $file->getStream();

        if ($dryRun) {
            // Parse CSV without persisting to show what would happen
            $lines = array_filter(explode("\n", trim($csvContent)));
            $preview = [];

            foreach ($lines as $line) {
                $parts = str_getcsv($line);
                if (count($parts) >= 2) {
                    $preview[] = [
                        'from_path' => $parts[0],
                        'to_path' => $parts[1],
                        'status_code' => (int) ($parts[2] ?? 301),
                    ];
                }
            }

            return Response::json([
                'dry_run' => true,
                'preview' => $preview,
                'total' => count($preview),
            ]);
        }

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        try {
            $result = $this->redirectManager->importCsv(
                csvContent: $csvContent,
                createdBy: $identity->id(),
                reason: 'Bulk import via admin',
                tenantId: $tenantId,
            );

            return Response::json($result);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Export all redirects as CSV.
     */
    public function export(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.seo.view');

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $redirects = $this->redirectManager->listAll(1, 10000, $tenantId);

        $csv = "from_path,to_path,status_code,locale,hits,last_hit,created_at\n";

        foreach ($redirects as $r) {
            $csv .= sprintf(
                "%s,%s,%d,%s,%d,%s,%s\n",
                $this->escapeCsv($r->fromPath),
                $this->escapeCsv($r->toPath),
                $r->statusCode,
                $this->escapeCsv($r->locale ?? ''),
                $r->hits,
                $this->escapeCsv($r->lastHitAt?->format('c') ?? ''),
                $this->escapeCsv($r->createdAt->format('c')),
            );
        }

        return new Response(
            statusCode: 200,
            headers: [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="redirects.csv"',
            ],
            body: $csv,
        );
    }

    private function escapeCsv(string $value): string
    {
        // Protect against CSV formula injection: prefix dangerous leading characters
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $value = "\t" . $value;
        }

        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }

}
