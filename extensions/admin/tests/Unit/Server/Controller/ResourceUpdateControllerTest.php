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
use Pulsar\Extension\Admin\Features\UpdateResource\UpdateResourceHandler;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryStoreInterface;
use Pulsar\Extension\Admin\Server\Controller\ResourceUpdateController;

#[CoversClass(ResourceUpdateController::class)]
final class ResourceUpdateControllerTest extends TestCase
{
    #[Test]
    public function updateReturnsJsonSuccess(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('name')->willReturn('users');
        $resource->method('label')->willReturn('User');
        $resource->method('fields')->willReturn([
            new FieldDefinition(name: 'name', type: FieldType::String, label: 'Name'),
        ]);
        $resource->method('operations')->willReturn([ResourceOperation::Update]);

        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('get')->willReturn($resource);

        $mutator = $this->createStub(ResourceMutatorInterface::class);
        $mutator->method('update')->willReturn(ActionResult::success('Updated'));

        $actionHistory = $this->createStub(ActionHistoryStoreInterface::class);

        $handler = new UpdateResourceHandler($registry, $mutator, $actionHistory);
        $config = AdminConfig::fromArray(['enabled' => true]);

        $controller = new ResourceUpdateController($handler, $registry, $config);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['name' => 'Jane']);
        $request->method('getAttribute')->willReturn(null);
        $request->method('getHeaderLine')
            ->willReturn('application/json');

        $response = $controller->update($request, 'users', '1');

        self::assertSame(200, $response->getStatusCode());
    }
}
