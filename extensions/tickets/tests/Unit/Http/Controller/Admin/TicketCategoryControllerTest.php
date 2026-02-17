<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Http\Controller\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Tickets\Contracts\TicketCategoryRepositoryInterface;
use Pulsar\Extension\Tickets\Domain\TicketCategory;
use Pulsar\Extension\Tickets\Http\Controller\Admin\TicketCategoryController;

#[CoversClass(TicketCategoryController::class)]
final class TicketCategoryControllerTest extends TestCase
{
    private TicketCategoryRepositoryInterface&Stub $categoryRepo;
    private GateInterface&Stub $gate;
    private TicketCategoryController $controller;

    protected function setUp(): void
    {
        $this->categoryRepo = $this->createStub(TicketCategoryRepositoryInterface::class);
        $this->gate = $this->createStub(GateInterface::class);
        $this->gate->method('denies')->willReturn(false);

        $this->controller = new TicketCategoryController(
            $this->categoryRepo,
            $this->gate,
        );
    }

    #[Test]
    public function indexReturnsJsonWithCategories(): void
    {
        $category = TicketCategory::create(
            id: 'cat-1',
            name: 'Billing',
            slug: 'billing',
            description: 'Billing issues',
        );
        $this->categoryRepo->method('findAll')->willReturn([$category]);

        $request = $this->authenticatedRequest('application/json');
        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['categories']);
        self::assertSame('Billing', $body['categories'][0]['name']);
        self::assertSame('billing', $body['categories'][0]['slug']);
    }

    #[Test]
    public function createReturns201WithNewCategory(): void
    {
        $request = $this->authenticatedRequest('application/json', [
            'name' => 'Support',
            'slug' => 'support',
            'description' => 'General support',
            'sort_order' => 5,
        ]);

        $response = $this->controller->create($request);

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Support', $body['name']);
        self::assertSame('support', $body['slug']);
        self::assertNotEmpty($body['id']);
    }

    #[Test]
    public function createReturns422WhenNameEmpty(): void
    {
        $request = $this->authenticatedRequest('application/json', [
            'name' => '',
            'slug' => '',
        ]);

        $response = $this->controller->create($request);

        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('error', $body);
    }

    #[Test]
    public function updateReturns404WhenCategoryNotFound(): void
    {
        $this->categoryRepo->method('findById')->willReturn(null);

        $request = $this->authenticatedRequest('application/json', ['name' => 'X', 'slug' => 'x']);
        $request->method('getAttribute')->willReturnMap([
            ['identity', null, $this->authenticatedIdentity()],
            ['id', '', 'nonexistent'],
        ]);

        $response = $this->controller->update($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function updateRenamesCategoryWhenFound(): void
    {
        $category = TicketCategory::create(id: 'cat-1', name: 'Old', slug: 'old');
        $this->categoryRepo->method('findById')->willReturn($category);

        $request = $this->authenticatedRequest('application/json', [
            'name' => 'New Name',
            'slug' => 'new-name',
        ]);
        $request->method('getAttribute')->willReturnMap([
            ['identity', null, $this->authenticatedIdentity()],
            ['id', '', 'cat-1'],
        ]);

        $response = $this->controller->update($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('New Name', $body['name']);
        self::assertSame('new-name', $body['slug']);
    }

    #[Test]
    public function deleteReturns404WhenCategoryNotFound(): void
    {
        $this->categoryRepo->method('findById')->willReturn(null);

        $request = $this->authenticatedRequest('application/json');
        $request->method('getAttribute')->willReturnMap([
            ['identity', null, $this->authenticatedIdentity()],
            ['id', '', 'nonexistent'],
        ]);

        $response = $this->controller->delete($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function deleteReturnsTrueWhenCategoryExists(): void
    {
        $category = TicketCategory::create(id: 'cat-1', name: 'ToDelete', slug: 'to-delete');
        $this->categoryRepo->method('findById')->willReturn($category);

        $request = $this->authenticatedRequest('application/json');
        $request->method('getAttribute')->willReturnMap([
            ['identity', null, $this->authenticatedIdentity()],
            ['id', '', 'cat-1'],
        ]);

        $response = $this->controller->delete($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertTrue($body['deleted']);
    }

    private function authenticatedIdentity(): IdentityInterface&Stub
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);

        return $identity;
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     */
    private function authenticatedRequest(string $accept, ?array $parsedBody = null): ServerRequestInterface&Stub
    {
        $identity = $this->authenticatedIdentity();

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn($accept);
        $request->method('getAttribute')->willReturnMap([
            ['identity', null, $identity],
            ['id', '', ''],
        ]);
        $request->method('getParsedBody')->willReturn($parsedBody);
        $request->method('getQueryParams')->willReturn([]);

        return $request;
    }
}
