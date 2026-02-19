<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Tools\ImportExportServiceInterface;
use Pulsar\Http\Message\Response;
use RuntimeException;

use function is_string;

/**
 * Admin controller for CMS data import.
 *
 * Supports dry-run validation before committing changes.
 * Execute operations require step-up authentication.
 */
#[Internal(reason: 'CMS admin controller — implementation detail')]
final readonly class ImportController
{
    public function __construct(
        private ImportExportServiceInterface $importExport,
        private GateInterface $gate,
    ) {}

    public function form(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.tools.import');

        return Response::json([
            'accepted_format' => 'application/json',
            'max_size_bytes' => 10 * 1024 * 1024,
            'supports_dry_run' => true,
        ]);
    }

    public function dryRun(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.tools.import');

        $jsonContent = $this->extractJsonContent($request);

        if ($jsonContent === null) {
            return Response::json(['error' => 'JSON content is required'], 400);
        }

        try {
            $result = $this->importExport->importBundle($jsonContent, dryRun: true);

            return Response::json($result->toArray());
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    public function execute(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.tools.import');
        $this->requireStepUp($request);

        $jsonContent = $this->extractJsonContent($request);

        if ($jsonContent === null) {
            return Response::json(['error' => 'JSON content is required'], 400);
        }

        try {
            $result = $this->importExport->importBundle($jsonContent, dryRun: false);

            return Response::json([
                'status' => 'imported',
                'result' => $result->toArray(),
            ]);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    private function extractJsonContent(ServerRequestInterface $request): ?string
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $content = $body['json_content'] ?? null;

        if (is_string($content) && $content !== '') {
            return $content;
        }

        // Try reading from request body directly
        $rawBody = (string) $request->getBody();

        return $rawBody !== '' ? $rawBody : null;
    }

    private function requireStepUp(ServerRequestInterface $request): void
    {
        $stepUp = $request->getAttribute('step_up_verified', false);

        if ($stepUp !== true) {
            throw new RuntimeException('Step-up authentication required for this action');
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
