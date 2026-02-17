<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Server\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Contracts\ResourceMutatorInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Domain\ActionResult;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Admin\Features\CreateResource\CreateResourceHandler;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryStoreInterface;
use Pulsar\Extension\Admin\Server\Controller\ResourceCreateController;

#[CoversClass(ResourceCreateController::class)]
final class ResourceCreateControllerTest extends TestCase
{
    #[Test]
    public function store_returns_json_success(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('name')->willReturn('users');
        $resource->method('label')->willReturn('User');
        $resource->method('fields')->willReturn([
            new FieldDefinition(name: 'name', type: FieldType::String, label: 'Name'),
        ]);
        $resource->method('operations')->willReturn([ResourceOperation::Create]);

        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('get')->willReturn($resource);

        $mutator = $this->createStub(ResourceMutatorInterface::class);
        $mutator->method('create')->willReturn(ActionResult::success('Created', ['id' => '1']));

        $actionHistory = $this->createStub(ActionHistoryStoreInterface::class);

        $handler = new CreateResourceHandler($registry, $mutator, $actionHistory);
        $config = AdminConfig::fromArray(['enabled' => true]);

        $controller = new ResourceCreateController($handler, $registry, $config);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['name' => 'John']);
        $request->method('getAttribute')->willReturn(null);
        $request->method('getHeaderLine')
            ->willReturn('application/json');

        $response = $controller->store($request, 'users');

        self::assertSame(201, $response->getStatusCode());
    }
}
