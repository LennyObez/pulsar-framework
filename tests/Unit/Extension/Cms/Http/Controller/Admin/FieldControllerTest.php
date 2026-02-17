<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\FieldRegistry\ContentTypeField;
use Pulsar\Extension\Cms\FieldRegistry\FieldRegistryRepositoryInterface;
use Pulsar\Extension\Cms\FieldRegistry\FieldType;
use Pulsar\Extension\Cms\Http\Controller\Admin\FieldController;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(FieldController::class)]
final class FieldControllerTest extends TestCase
{
    #[Test]
    public function index_returns_fields_for_content_type(): void
    {
        $field = new ContentTypeField(
            id: 'field-1',
            contentType: 'article',
            fieldKey: 'summary',
            fieldType: FieldType::String,
            required: true,
            translatable: true,
            searchable: true,
            filterable: false,
            sortable: false,
            validationRules: ['max' => 500],
            defaultValue: null,
            sortOrder: 1,
        );

        $repo = $this->createStub(FieldRegistryRepositoryInterface::class);
        $repo->method('findFieldsByContentType')->willReturn([$field]);

        $controller = new FieldController(fieldRepository: $repo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->index($request, 'article');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('article', $body['contentType']);

        /** @var list<array<string, mixed>> $fields */
        $fields = $body['fields'];
        self::assertCount(1, $fields);
        self::assertSame('field-1', $fields[0]['id']);
        self::assertSame('summary', $fields[0]['field_key']);
        self::assertSame('string', $fields[0]['field_type']);
        self::assertTrue($fields[0]['required']);
        self::assertTrue($fields[0]['translatable']);
        self::assertTrue($fields[0]['searchable']);
        self::assertFalse($fields[0]['filterable']);
    }

    #[Test]
    public function index_returns_empty_list_for_unknown_content_type(): void
    {
        $repo = $this->createStub(FieldRegistryRepositoryInterface::class);
        $repo->method('findFieldsByContentType')->willReturn([]);

        $controller = new FieldController(fieldRepository: $repo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->index($request, 'nonexistent');

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<mixed> $fields */
        $fields = $body['fields'];
        self::assertCount(0, $fields);
    }

    #[Test]
    public function create_stores_new_field_and_returns_201(): void
    {
        $repo = $this->createStub(FieldRegistryRepositoryInterface::class);

        $controller = new FieldController(fieldRepository: $repo);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'field_key' => 'author_bio',
            'field_type' => 'rich_text',
            'required' => true,
            'translatable' => true,
            'searchable' => false,
            'sort_order' => 5,
        ]);

        $response = $controller->create($request, 'article');

        self::assertSame(201, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('author_bio', $body['field_key']);
        self::assertSame('created', $body['status']);
        self::assertIsString($body['id']);
        self::assertNotEmpty($body['id']);
    }

    #[Test]
    public function create_returns_400_when_field_key_missing(): void
    {
        $repo = $this->createStub(FieldRegistryRepositoryInterface::class);

        $controller = new FieldController(fieldRepository: $repo);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'field_key' => '',
            'field_type' => 'string',
        ]);

        $response = $controller->create($request, 'article');

        self::assertSame(400, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['error']);
        self::assertStringContainsString('field_key', (string) $body['error']);
    }

    #[Test]
    public function create_returns_400_when_field_type_missing(): void
    {
        $repo = $this->createStub(FieldRegistryRepositoryInterface::class);

        $controller = new FieldController(fieldRepository: $repo);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'field_key' => 'title',
            'field_type' => '',
        ]);

        $response = $controller->create($request, 'article');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function create_returns_400_for_invalid_field_type(): void
    {
        $repo = $this->createStub(FieldRegistryRepositoryInterface::class);

        $controller = new FieldController(fieldRepository: $repo);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'field_key' => 'title',
            'field_type' => 'invalid_type',
        ]);

        $response = $controller->create($request, 'article');

        self::assertSame(400, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['error']);
        self::assertStringContainsString('Invalid field_type', (string) $body['error']);
    }

    #[Test]
    public function update_modifies_existing_field(): void
    {
        $existing = new ContentTypeField(
            id: 'field-1',
            contentType: 'article',
            fieldKey: 'summary',
            fieldType: FieldType::String,
            required: false,
            translatable: false,
            searchable: false,
            filterable: false,
            sortable: false,
            validationRules: [],
            defaultValue: null,
            sortOrder: 0,
        );

        $repo = $this->createStub(FieldRegistryRepositoryInterface::class);
        $repo->method('findFieldsByContentType')->willReturn([$existing]);

        $controller = new FieldController(fieldRepository: $repo);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'field_key' => 'description',
            'required' => true,
        ]);

        $response = $controller->update($request, 'article', 'field-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('field-1', $body['id']);
        self::assertSame('description', $body['field_key']);
        self::assertSame('updated', $body['status']);
    }

    #[Test]
    public function update_returns_404_when_field_not_found(): void
    {
        $repo = $this->createStub(FieldRegistryRepositoryInterface::class);
        $repo->method('findFieldsByContentType')->willReturn([]);

        $controller = new FieldController(fieldRepository: $repo);
        $request = $this->createAuthenticatedRequest(parsedBody: []);

        $response = $controller->update($request, 'article', 'nonexistent');

        self::assertSame(404, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['error']);
        self::assertStringContainsString('not found', (string) $body['error']);
    }

    #[Test]
    public function delete_returns_success_for_existing_field(): void
    {
        $existing = new ContentTypeField(
            id: 'field-1',
            contentType: 'article',
            fieldKey: 'summary',
            fieldType: FieldType::String,
            required: false,
            translatable: false,
            searchable: false,
            filterable: false,
            sortable: false,
            validationRules: [],
            defaultValue: null,
            sortOrder: 0,
        );

        $repo = $this->createStub(FieldRegistryRepositoryInterface::class);
        $repo->method('findFieldsByContentType')->willReturn([$existing]);

        $controller = new FieldController(fieldRepository: $repo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->delete($request, 'article', 'field-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('field-1', $body['id']);
        self::assertSame('deleted', $body['status']);
    }

    #[Test]
    public function delete_returns_404_when_field_not_found(): void
    {
        $repo = $this->createStub(FieldRegistryRepositoryInterface::class);
        $repo->method('findFieldsByContentType')->willReturn([]);

        $controller = new FieldController(fieldRepository: $repo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->delete($request, 'article', 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function index_throws_when_unauthenticated(): void
    {
        $repo = $this->createStub(FieldRegistryRepositoryInterface::class);
        $controller = new FieldController(fieldRepository: $repo);

        $this->expectException(AuthenticationException::class);
        $controller->index($this->createUnauthenticatedRequest(), 'article');
    }

    #[Test]
    public function index_throws_when_authorization_denied(): void
    {
        $repo = $this->createStub(FieldRegistryRepositoryInterface::class);
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = new FieldController(fieldRepository: $repo, gate: $gate);
        $request = $this->createAuthenticatedRequest();

        $this->expectException(AuthorizationException::class);
        $controller->index($request, 'article');
    }

    #[Test]
    public function create_throws_when_unauthenticated(): void
    {
        $repo = $this->createStub(FieldRegistryRepositoryInterface::class);
        $controller = new FieldController(fieldRepository: $repo);

        $this->expectException(AuthenticationException::class);
        $controller->create($this->createUnauthenticatedRequest(), 'article');
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     */
    private function createAuthenticatedRequest(?array $parsedBody = null): ServerRequestInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('admin-1');

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/fields');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => match ($name) {
                'identity' => $identity,
                default => $default,
            },
        );

        if ($parsedBody !== null) {
            $request->method('getParsedBody')->willReturn($parsedBody);
        }

        return $request;
    }

    private function createUnauthenticatedRequest(): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/fields');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
