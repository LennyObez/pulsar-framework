<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\FieldRegistry\ContentFieldValue;
use Pulsar\Extension\Cms\FieldRegistry\ContentTypeField;
use Pulsar\Extension\Cms\FieldRegistry\FieldRegistryRepositoryInterface;
use Pulsar\Extension\Cms\FieldRegistry\FieldType;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[Internal(reason: 'Raw-DB repository — use FieldRegistryRepositoryInterface for public API')]
final readonly class DbFieldRegistryRepository implements FieldRegistryRepositoryInterface
{
    private const string SQL_FIND_FIELDS = <<<'SQL'
        SELECT * FROM cms_content_type_fields
        WHERE content_type = :content_type
        ORDER BY sort_order ASC
        SQL;

    private const array UPSERT_FIELD_COLUMNS = [
        'id', 'content_type', 'field_key', 'field_type', 'required', 'translatable',
        'searchable', 'filterable', 'sortable', 'validation_rules', 'default_value', 'sort_order',
    ];

    private const array UPSERT_FIELD_UPDATE = [
        'field_type', 'required', 'translatable', 'searchable', 'filterable',
        'sortable', 'validation_rules', 'default_value', 'sort_order',
    ];

    private const string SQL_FIND_VALUES = <<<'SQL'
        SELECT * FROM cms_content_field_values
        WHERE content_id = :content_id
        SQL;

    private const array UPSERT_VALUE_COLUMNS = [
        'id', 'content_id', 'field_id', 'locale',
        'value_string', 'value_int', 'value_float', 'value_bool', 'value_datetime', 'value_json',
    ];

    private const array UPSERT_VALUE_UPDATE = [
        'value_string', 'value_int', 'value_float', 'value_bool', 'value_datetime', 'value_json',
    ];

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findFieldsByContentType(string $contentType): array
    {
        $result = $this->connection->query(self::SQL_FIND_FIELDS, [
            'content_type' => $contentType,
        ]);

        return $result->map(self::hydrateField(...));
    }

    public function saveField(ContentTypeField $field): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'cms_content_type_fields',
            self::UPSERT_FIELD_COLUMNS,
            ['id'],
            self::UPSERT_FIELD_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $field->id,
            'content_type' => $field->contentType,
            'field_key' => $field->fieldKey,
            'field_type' => $field->fieldType->value,
            'required' => $field->required,
            'translatable' => $field->translatable,
            'searchable' => $field->searchable,
            'filterable' => $field->filterable,
            'sortable' => $field->sortable,
            'validation_rules' => $field->validationRules !== []
                ? json_encode($field->validationRules, JSON_THROW_ON_ERROR)
                : null,
            'default_value' => $field->defaultValue !== null
                ? json_encode($field->defaultValue, JSON_THROW_ON_ERROR)
                : null,
            'sort_order' => $field->sortOrder,
        ]);
    }

    public function saveValue(ContentFieldValue $value): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'cms_content_field_values',
            self::UPSERT_VALUE_COLUMNS,
            ['id'],
            self::UPSERT_VALUE_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $value->id,
            'content_id' => $value->contentId,
            'field_id' => $value->fieldId,
            'locale' => $value->locale,
            'value_string' => $value->valueString,
            'value_int' => $value->valueInt,
            'value_float' => $value->valueFloat,
            'value_bool' => $value->valueBool,
            'value_datetime' => $value->valueDatetime?->format('c'),
            'value_json' => $value->valueJson !== null
                ? json_encode($value->valueJson, JSON_THROW_ON_ERROR)
                : null,
        ]);
    }

    public function findValues(string $contentId, ?string $locale = null): array
    {
        $sql = self::SQL_FIND_VALUES;
        $bindings = ['content_id' => $contentId];

        if ($locale !== null) {
            $sql .= ' AND (locale = :locale OR locale IS NULL)';
            $bindings['locale'] = $locale;
        }

        $sql .= ' ORDER BY field_id';

        $result = $this->connection->query($sql, $bindings);

        return $result->map(self::hydrateValue(...));
    }

    private static function hydrateField(Row $row): ContentTypeField
    {
        $validationRaw = $row->getNullableString('validation_rules');
        $defaultRaw = $row->getNullableString('default_value');

        return new ContentTypeField(
            id: $row->getString('id'),
            contentType: $row->getString('content_type'),
            fieldKey: $row->getString('field_key'),
            fieldType: FieldType::from($row->getString('field_type')),
            required: $row->getBool('required'),
            translatable: $row->getBool('translatable'),
            searchable: $row->getBool('searchable'),
            filterable: $row->getBool('filterable'),
            sortable: $row->getBool('sortable'),
            validationRules: $validationRaw !== null
                ? json_decode($validationRaw, true, 512, JSON_THROW_ON_ERROR)
                : [],
            defaultValue: $defaultRaw !== null
                ? json_decode($defaultRaw, true, 512, JSON_THROW_ON_ERROR)
                : null,
            sortOrder: $row->getInt('sort_order'),
        );
    }

    private static function hydrateValue(Row $row): ContentFieldValue
    {
        $jsonRaw = $row->getNullableString('value_json');
        $datetimeRaw = $row->getNullableString('value_datetime');

        return new ContentFieldValue(
            id: $row->getString('id'),
            contentId: $row->getString('content_id'),
            fieldId: $row->getString('field_id'),
            locale: $row->getNullableString('locale'),
            valueString: $row->getNullableString('value_string'),
            valueInt: $row->getNullableInt('value_int'),
            valueFloat: $row->getOrDefault('value_float') !== null
                ? $row->getFloat('value_float')
                : null,
            valueBool: $row->getOrDefault('value_bool') !== null
                ? $row->getBool('value_bool')
                : null,
            valueDatetime: $datetimeRaw !== null ? new DateTimeImmutable($datetimeRaw) : null,
            valueJson: $jsonRaw !== null
                ? json_decode($jsonRaw, true, 512, JSON_THROW_ON_ERROR)
                : null,
        );
    }
}
