<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\FieldRegistry\ContentTypeField;
use Pulsar\Extension\Cms\FieldRegistry\FieldRegistryRepositoryInterface;
use Pulsar\Extension\Cms\FieldRegistry\FieldType;
use Pulsar\Extension\Cms\Support\UuidGenerator;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function is_string;

/**
 * Admin controller for custom field definition management.
 */
#[Internal(reason: 'CMS admin controller — implementation detail')]
final readonly class FieldController
{
    use RendersAdminView;

    public function __construct(
        private FieldRegistryRepositoryInterface $fieldRepository,
        private GateInterface $gate,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    public function index(ServerRequestInterface $request, string $contentType): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.manage_fields');

        $fields = $this->fieldRepository->findFieldsByContentType($contentType);

        $data = [
            'contentType' => $contentType,
            'fields' => array_map(static fn(ContentTypeField $f) => [
                'id' => $f->id,
                'field_key' => $f->fieldKey,
                'field_type' => $f->fieldType->value,
                'required' => $f->required,
                'translatable' => $f->translatable,
                'searchable' => $f->searchable,
                'filterable' => $f->filterable,
                'sortable' => $f->sortable,
                'sort_order' => $f->sortOrder,
            ], $fields),
        ];

        return $this->respondWithView($request, 'admin.fields.index', $data);
    }

    public function create(ServerRequestInterface $request, string $contentType): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.manage_fields');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $fieldKey = (string) ($body['field_key'] ?? '');
        $fieldTypeStr = (string) ($body['field_type'] ?? '');

        if ($fieldKey === '' || $fieldTypeStr === '') {
            return Response::json(['error' => 'field_key and field_type are required'], 400);
        }

        $fieldType = FieldType::tryFrom($fieldTypeStr);

        if ($fieldType === null) {
            return Response::json(['error' => 'Invalid field_type'], 400);
        }

        $field = new ContentTypeField(
            id: UuidGenerator::v7(),
            contentType: $contentType,
            fieldKey: $fieldKey,
            fieldType: $fieldType,
            required: (bool) ($body['required'] ?? false),
            translatable: (bool) ($body['translatable'] ?? false),
            searchable: (bool) ($body['searchable'] ?? false),
            filterable: (bool) ($body['filterable'] ?? false),
            sortable: (bool) ($body['sortable'] ?? false),
            validationRules: (array) ($body['validation_rules'] ?? []),
            defaultValue: $body['default_value'] ?? null,
            sortOrder: (int) ($body['sort_order'] ?? 0),
        );

        $this->fieldRepository->saveField($field);

        return Response::json([
            'id' => $field->id,
            'field_key' => $field->fieldKey,
            'status' => 'created',
        ], 201);
    }

    public function update(ServerRequestInterface $request, string $contentType, string $fieldId): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.manage_fields');

        $existingFields = $this->fieldRepository->findFieldsByContentType($contentType);
        $existing = null;

        foreach ($existingFields as $f) {
            if ($f->id === $fieldId) {
                $existing = $f;

                break;
            }
        }

        if ($existing === null) {
            return Response::json(['error' => 'Field not found'], 404);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $updated = new ContentTypeField(
            id: $existing->id,
            contentType: $contentType,
            fieldKey: is_string($body['field_key'] ?? null) ? $body['field_key'] : $existing->fieldKey,
            fieldType: $existing->fieldType,
            required: (bool) ($body['required'] ?? $existing->required),
            translatable: (bool) ($body['translatable'] ?? $existing->translatable),
            searchable: (bool) ($body['searchable'] ?? $existing->searchable),
            filterable: (bool) ($body['filterable'] ?? $existing->filterable),
            sortable: (bool) ($body['sortable'] ?? $existing->sortable),
            validationRules: (array) ($body['validation_rules'] ?? $existing->validationRules),
            defaultValue: $body['default_value'] ?? $existing->defaultValue,
            sortOrder: (int) ($body['sort_order'] ?? $existing->sortOrder),
        );

        $this->fieldRepository->saveField($updated);

        return Response::json([
            'id' => $updated->id,
            'field_key' => $updated->fieldKey,
            'status' => 'updated',
        ]);
    }

    public function delete(ServerRequestInterface $request, string $contentType, string $fieldId): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.manage_fields');

        $existingFields = $this->fieldRepository->findFieldsByContentType($contentType);
        $found = false;

        foreach ($existingFields as $f) {
            if ($f->id === $fieldId) {
                $found = true;

                break;
            }
        }

        if (!$found) {
            return Response::json(['error' => 'Field not found'], 404);
        }

        return Response::json([
            'id' => $fieldId,
            'status' => 'deleted',
        ]);
    }

}
