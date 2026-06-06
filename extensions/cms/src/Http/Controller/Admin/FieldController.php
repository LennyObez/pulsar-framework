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

use function is_bool;
use function is_int;
use function is_string;

/**
 * Admin controller for custom field definition management.
 */
#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class FieldController extends AbstractAdminController
{
    public function __construct(
        private FieldRegistryRepositoryInterface $fieldRepository,
        ?GateInterface $gate = null,
        ?TemplateEngineInterface $templateEngine = null,
    ) {
        parent::__construct($templateEngine, $gate);
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

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
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function create(ServerRequestInterface $request, string $contentType): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.manage_fields');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawFieldKey */
        $rawFieldKey = $body['field_key'] ?? null;
        $fieldKey = is_string($rawFieldKey) ? $rawFieldKey : '';
        /** @var mixed $rawFieldType */
        $rawFieldType = $body['field_type'] ?? null;
        $fieldTypeStr = is_string($rawFieldType) ? $rawFieldType : '';

        if ($fieldKey === '' || $fieldTypeStr === '') {
            return Response::json(['error' => 'field_key and field_type are required'], 400);
        }

        $fieldType = FieldType::tryFrom($fieldTypeStr);

        if ($fieldType === null) {
            return Response::json(['error' => 'Invalid field_type'], 400);
        }

        /** @var mixed $rawRequired */
        $rawRequired = $body['required'] ?? null;
        /** @var mixed $rawTranslatable */
        $rawTranslatable = $body['translatable'] ?? null;
        /** @var mixed $rawSearchable */
        $rawSearchable = $body['searchable'] ?? null;
        /** @var mixed $rawFilterable */
        $rawFilterable = $body['filterable'] ?? null;
        /** @var mixed $rawSortable */
        $rawSortable = $body['sortable'] ?? null;
        /** @var mixed $rawSortOrder */
        $rawSortOrder = $body['sort_order'] ?? null;
        $field = new ContentTypeField(
            id: UuidGenerator::v7(),
            contentType: $contentType,
            fieldKey: $fieldKey,
            fieldType: $fieldType,
            required: is_bool($rawRequired) ? $rawRequired : false,
            translatable: is_bool($rawTranslatable) ? $rawTranslatable : false,
            searchable: is_bool($rawSearchable) ? $rawSearchable : false,
            filterable: is_bool($rawFilterable) ? $rawFilterable : false,
            sortable: is_bool($rawSortable) ? $rawSortable : false,
            validationRules: self::toStringKeyedArray($body['validation_rules'] ?? []),
            defaultValue: $body['default_value'] ?? null,
            sortOrder: is_int($rawSortOrder) ? $rawSortOrder : 0,
        );

        $this->fieldRepository->saveField($field);

        return Response::json([
            'id' => $field->id,
            'field_key' => $field->fieldKey,
            'status' => 'created',
        ], 201);
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

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

        /** @var mixed $rawFieldKey */
        $rawFieldKey = $body['field_key'] ?? null;
        /** @var mixed $rawRequired */
        $rawRequired = $body['required'] ?? null;
        /** @var mixed $rawTranslatable */
        $rawTranslatable = $body['translatable'] ?? null;
        /** @var mixed $rawSearchable */
        $rawSearchable = $body['searchable'] ?? null;
        /** @var mixed $rawFilterable */
        $rawFilterable = $body['filterable'] ?? null;
        /** @var mixed $rawSortable */
        $rawSortable = $body['sortable'] ?? null;
        /** @var mixed $rawSortOrder */
        $rawSortOrder = $body['sort_order'] ?? null;

        $updated = new ContentTypeField(
            id: $existing->id,
            contentType: $contentType,
            fieldKey: is_string($rawFieldKey) ? $rawFieldKey : $existing->fieldKey,
            fieldType: $existing->fieldType,
            required: is_bool($rawRequired) ? $rawRequired : $existing->required,
            translatable: is_bool($rawTranslatable) ? $rawTranslatable : $existing->translatable,
            searchable: is_bool($rawSearchable) ? $rawSearchable : $existing->searchable,
            filterable: is_bool($rawFilterable) ? $rawFilterable : $existing->filterable,
            sortable: is_bool($rawSortable) ? $rawSortable : $existing->sortable,
            validationRules: self::toStringKeyedArray($body['validation_rules'] ?? $existing->validationRules),
            defaultValue: $body['default_value'] ?? $existing->defaultValue,
            sortOrder: is_int($rawSortOrder) ? $rawSortOrder : $existing->sortOrder,
        );

        $this->fieldRepository->saveField($updated);

        return Response::json([
            'id' => $updated->id,
            'field_key' => $updated->fieldKey,
            'status' => 'updated',
        ]);
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

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

    /**
     * @return array<string, mixed>
     */
    private static function toStringKeyedArray(mixed $value): array
    {
        /** @var array<string, mixed> $result */
        $result = (array) $value;

        return $result;
    }
}
