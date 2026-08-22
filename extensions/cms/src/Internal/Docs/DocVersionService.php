<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Docs;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Docs\DocVersionServiceInterface;
use Pulsar\Extension\Cms\Internal\Persistence\DbDocVersionRepository;

use function array_map;

/**
 * @psalm-api Bound to DocVersionServiceInterface in the CMS service provider;
 *            resolved from the DI container, never instantiated by name.
 */
#[Internal(reason: 'Internal implementation; use DocVersionServiceInterface for public API')]
final readonly class DocVersionService implements DocVersionServiceInterface
{
    public function __construct(
        private DbDocVersionRepository $repository,
    ) {}

    /**
     * @return list<array{slug: string, label: string, is_default: bool, is_archived: bool}>
     */
    public function listVersions(): array
    {
        $rows = $this->repository->findAll();

        return array_map(static fn(array $row): array => [
            'slug' => $row['slug'],
            'label' => $row['label'],
            'is_default' => $row['is_default'],
            'is_archived' => $row['is_archived'],
        ], $rows);
    }

    public function getDefaultVersion(): ?string
    {
        $row = $this->repository->findDefault();

        return $row !== null ? $row['slug'] : null;
    }

    public function setDefaultVersion(string $versionSlug): void
    {
        $this->repository->setDefault($versionSlug);
    }
}
