<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Http\Message\Response;

use function str_contains;

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

    public function index(ServerRequestInterface $request): Response
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

        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return Response::json(['resources' => $resources]);
        }

        return Response::html($this->renderView([
            'resources' => $resources,
            'schema_enabled' => $this->config->schema->enabled,
        ]));
    }

    /**
     * @param array<string, mixed> $templateData
     */
    private function renderView(array $templateData): string
    {
        $title = 'Resources';
        $content = 'resources-index';
        ob_start();
        include __DIR__ . '/../View/templates/admin/layout.php';

        return (string) ob_get_clean();
    }
}
