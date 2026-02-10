<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;

/**
 * Controller listing all registered admin resources.
 */
#[Internal]
final readonly class ResourceIndexController
{
    public function __construct(
        private ResourceRegistryInterface $registry,
        private AdminConfig $config,
    ) {}

    public function index(Request $request): Response
    {
        $resources = [];
        foreach ($this->registry->all() as $name => $resource) {
            $resources[] = [
                'name' => $name,
                'label' => $resource->label(),
                'plural_label' => $resource->pluralLabel(),
                'icon' => $resource->icon(),
                'operations' => array_map(
                    static fn($op): string => $op->value,
                    $resource->operations(),
                ),
            ];
        }

        if ($request->wantsJson()) {
            return Response::json(['resources' => $resources]);
        }

        return Response::html($this->renderView('Resources', 'resources-index', [
            'resources' => $resources,
            'schema_enabled' => $this->config->schema->enabled,
        ]));
    }

    /**
     * @param array<string, mixed> $templateData
     */
    private function renderView(string $title, string $content, array $templateData): string
    {
        ob_start();
        include __DIR__ . '/../View/templates/admin/layout.php';

        return (string) ob_get_clean();
    }
}
