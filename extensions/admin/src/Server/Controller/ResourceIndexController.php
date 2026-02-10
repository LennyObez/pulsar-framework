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
        private readonly ResourceRegistryInterface $registry,
        private readonly AdminConfig $config,
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

        return Response::html($this->renderHtml($resources));
    }

    /**
     * @param list<array{name: string, label: string, plural_label: string, icon: string, operations: list<string>}> $resources
     */
    private function renderHtml(array $resources): string
    {
        ob_start();
        $title = 'Resources';
        $content = 'resources-index';
        $templateData = ['resources' => $resources, 'schema_enabled' => $this->config->schema->enabled];
        include __DIR__ . '/../View/templates/admin/layout.php';

        return (string) ob_get_clean();
    }
}
