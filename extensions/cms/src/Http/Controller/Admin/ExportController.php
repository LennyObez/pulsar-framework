<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Tools\ExportOptions;
use Pulsar\Extension\Cms\Tools\ImportExportServiceInterface;
use Pulsar\Http\Message\Response;
use RuntimeException;

use function is_array;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Admin controller for CMS data export.
 *
 * Generates a portable JSON bundle with an evidence hash for integrity
 * verification. The evidence hash is included as a response header.
 */
#[Internal(reason: 'CMS admin controller — implementation detail')]
final readonly class ExportController
{
    public function __construct(
        private ImportExportServiceInterface $importExport,
        private GateInterface $gate,
    ) {}

    public function form(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.tools.export');

        return Response::json([
            'scopes' => ['content', 'taxonomies', 'menus', 'settings', 'media_refs'],
            'options' => [
                'include_pii' => false,
                'locales' => null,
            ],
        ]);
    }

    public function download(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.tools.export');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var list<string> $scope */
        $scope = is_array($body['scope'] ?? null) ? $body['scope'] : [];

        if ($scope === []) {
            return Response::json(['error' => 'At least one export scope is required'], 400);
        }

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        try {
            $options = ExportOptions::fromArray([
                'scope' => $scope,
                'locales' => is_array($body['locales'] ?? null) ? $body['locales'] : null,
                'include_pii' => (bool) ($body['include_pii'] ?? false),
                'tenant_id' => $tenantId,
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }

        $bundle = $this->importExport->exportBundle($options);

        $json = json_encode($bundle->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new Response(
            statusCode: 200,
            headers: [
                'Content-Type' => 'application/json; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="cms-export.json"',
                'X-Evidence-Hash' => $bundle->evidenceHash,
            ],
            body: $json,
        );
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
