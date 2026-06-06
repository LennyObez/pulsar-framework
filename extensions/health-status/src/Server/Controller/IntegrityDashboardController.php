<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Server\Controller;

use Pulsar\Api\Internal;
use Pulsar\Extension\HealthStatus\Contracts\IntegrityVerificationRunnerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Integrity\FileVerificationResult;

use function array_map;

/**
 * Handles the integrity verification dashboard and API.
 *
 * Renders a table showing file path, expected hash, actual hash, and status
 * for every tracked file in the integrity manifest.
 */
#[Internal]
final readonly class IntegrityDashboardController
{
    use RendersStatusView;
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function __construct(
        private IntegrityVerificationRunnerInterface $runner,
    ) {}

    /**
     * GET /_pulsar/status/integrity: HTML integrity dashboard.
     */
    public function __invoke(): Response
    {
        $result = $this->runner->run();

        $html = $this->renderView('File Integrity', 'integrity', [
            'result' => $result,
        ]);

        return Response::html($html);
    }

    /**
     * GET /_pulsar/status/api/integrity: JSON integrity results.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function api(): Response
    {
        $result = $this->runner->run();

        return Response::json([
            'passed' => $result->passed,
            'verified' => $result->verified,
            'modified' => $result->modified,
            'missing' => $result->missing,
            'added' => $result->added,
            'files' => array_map(
                static fn(FileVerificationResult $f): array => [
                    'path' => $f->path,
                    'expected_hash' => $f->expectedHash,
                    'actual_hash' => $f->actualHash,
                    'status' => $f->status->value,
                ],
                $result->files,
            ),
        ]);
    }
}
