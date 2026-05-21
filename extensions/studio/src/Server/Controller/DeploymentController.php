<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Server\Controller;

use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Internal\Diagnostics\GitLogReader;
use Pulsar\Http\Message\Response;

use function count;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Handles GET /studio/console/deployments: deployment diff viewer.
 *
 * Shows what changed between deployments using git log between version tags.
 * Provides commit history, file change summaries, and diff statistics.
 * Delegates all git operations to GitLogReader (no direct process execution).
 */
#[Internal]
final readonly class DeploymentController
{
    use RendersStudioView;

    public function __construct(
        private GitLogReader $gitLog,
    ) {}

    /**
     * @throws JsonException
     */
    public function handle(ServerRequestInterface $_request): Response
    {
        $tags = $this->gitLog->getVersionTags();
        $currentRef = $this->gitLog->getCurrentRef();
        $deployments = [];

        $tagCount = count($tags);

        for ($i = 0; $i < $tagCount && $i < 20; $i++) {
            /** @var string $tag */
            $tag = $tags[$i];
            /** @var string|null $previousTag */
            $previousTag = $i + 1 < $tagCount ? $tags[$i + 1] : null;

            $deployment = [
                'tag' => $tag,
                'previous_tag' => $previousTag,
                'commits' => $this->gitLog->getCommitsBetween($previousTag, $tag),
                'stats' => $previousTag !== null ? $this->gitLog->getDiffStats($previousTag, $tag) : null,
            ];

            $deployments[] = $deployment;
        }

        $dataJson = json_encode([
            'deployments' => $deployments,
            'current_ref' => $currentRef,
            'tag_count' => $tagCount,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $html = $this->renderStudioView('Deployments - Pulsar Studio', 'console/deployments', [
            'dataJson' => $dataJson,
        ]);

        return Response::html($html);
    }
}
