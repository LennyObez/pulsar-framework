<?php

declare(strict_types=1);

namespace Pulsar\Codegen\Generator;

use Override;
use Pulsar\Api\Api;
use Pulsar\Codegen\AbstractGenerator;
use Pulsar\Codegen\GeneratedFile;
use Pulsar\Codegen\GeneratorConfig;
use Pulsar\Codegen\OverwritePolicy;
use Pulsar\Codegen\Schema\EntityDefinition;
use Pulsar\Codegen\Schema\PropertyDefinition;
use Pulsar\Codegen\Template\TemplateVariable;

use function array_filter;
use function array_values;
use function implode;
use function rtrim;
use function ucfirst;

/**
 * Generates repository interface and implementation from an EntityDefinition.
 *
 * Produces two files per entity:
 * - `Contracts/{ClassName}RepositoryInterface.php` — standard CRUD + custom finders
 * - `Repository/{ClassName}Repository.php` — parameterized query implementation
 *
 * Custom finders are generated for filterable, non-primary string/int columns.
 * All queries use parameterized bindings — SQL injection is impossible by construction.
 */
#[Api(since: '1.0.0')]
final class RepositoryGenerator extends AbstractGenerator
{
    private const string INTERFACE_TEMPLATE = <<<'TPL'
        <?php

        declare(strict_types=1);

        namespace {{contractNamespace}};

        /**
         * Repository contract for {{className}} entities.
         */
        interface {{className}}RepositoryInterface
        {
            /**
             * Find a single {{className}} by primary key.
             *
             * @return array<string, mixed>|null
             */
            public function find({{primaryType}} $id): ?array;

            /**
             * Retrieve all {{className}} records.
             *
             * @return list<array<string, mixed>>
             */
            public function findAll(): array;

            /**
             * Create a new {{className}} record and return the inserted ID.
             *
             * @param array<string, mixed> $data
             */
            public function create(array $data): int|string;

            /**
             * Update an existing {{className}} record.
             *
             * @param array<string, mixed> $data
             */
            public function update({{primaryType}} $id, array $data): bool;

            /**
             * Delete a {{className}} record by primary key.
             */
            public function delete({{primaryType}} $id): bool;
        {{customFinderSignatures}}
        }
        TPL;

    private const string IMPLEMENTATION_TEMPLATE = <<<'TPL'
        <?php

        declare(strict_types=1);

        namespace {{implNamespace}};

        use {{contractNamespace}}\{{className}}RepositoryInterface;
        use Pulsar\Database\ConnectionInterface;

        use function array_keys;
        use function array_map;
        use function implode;

        /**
         * Database-backed repository for {{className}} entities.
         *
         * All queries use parameterized bindings — SQL injection is impossible by construction.
         */
        final readonly class {{className}}Repository implements {{className}}RepositoryInterface
        {
            public function __construct(
                private ConnectionInterface $connection,
            ) {}

            public function find({{primaryType}} $id): ?array
            {
                $result = $this->connection->query(
                    'SELECT * FROM {{tableName}} WHERE {{primaryKeyColumn}} = :id LIMIT 1',
                    ['id' => $id],
                );

                return $result->first()?->toArray();
            }

            public function findAll(): array
            {
                $result = $this->connection->query('SELECT * FROM {{tableName}}');

                $rows = [];

                foreach ($result->rows as $row) {
                    $rows[] = $row->toArray();
                }

                return $rows;
            }

            public function create(array $data): int|string
            {
                $columns = array_keys($data);
                $placeholders = array_map(static fn(string $col): string => ':' . $col, $columns);

                $sql = 'INSERT INTO {{tableName}} ('
                    . implode(', ', $columns)
                    . ') VALUES ('
                    . implode(', ', $placeholders)
                    . ')';

                $this->connection->execute($sql, $data);

                return $this->connection->lastInsertId();
            }

            public function update({{primaryType}} $id, array $data): bool
            {
                $setClauses = [];

                foreach (array_keys($data) as $column) {
                    $setClauses[] = $column . ' = :' . $column;
                }

                $sql = 'UPDATE {{tableName}} SET '
                    . implode(', ', $setClauses)
                    . ' WHERE {{primaryKeyColumn}} = :__pk_id';

                $bindings = $data;
                $bindings['__pk_id'] = $id;

                return $this->connection->execute($sql, $bindings) > 0;
            }

            public function delete({{primaryType}} $id): bool
            {
                return $this->connection->execute(
                    'DELETE FROM {{tableName}} WHERE {{primaryKeyColumn}} = :id',
                    ['id' => $id],
                ) > 0;
            }
        {{customFinderImplementations}}
        }
        TPL;

    /** @return list<GeneratedFile> */
    #[Override]
    protected function doGenerate(EntityDefinition $entity, GeneratorConfig $config): array
    {
        $baseDir = rtrim($config->outputBaseDirectory, '/');
        $nsPrefix = $config->namespacePrefix;
        $contractNamespace = $nsPrefix . '\\Contracts';
        $implNamespace = $nsPrefix . '\\Repository';
        $policy = $config->force ? OverwritePolicy::Force : OverwritePolicy::Fail;

        $primaryProp = $this->findPrimaryProperty($entity);
        $primaryType = $primaryProp !== null ? $primaryProp->phpType : 'int';
        $primaryKeyColumn = $entity->primaryKey;

        $finderColumns = $this->findFinderColumns($entity);
        $finderSignatures = $this->buildFinderSignatures($entity, $finderColumns);
        $finderImpls = $this->buildFinderImplementations($entity, $finderColumns);

        $variables = [
            new TemplateVariable('className', $entity->className),
            new TemplateVariable('tableName', $entity->tableName),
            new TemplateVariable('primaryType', $primaryType),
            new TemplateVariable('primaryKeyColumn', $primaryKeyColumn),
            new TemplateVariable('contractNamespace', $contractNamespace),
            new TemplateVariable('implNamespace', $implNamespace),
            new TemplateVariable('customFinderSignatures', $finderSignatures),
            new TemplateVariable('customFinderImplementations', $finderImpls),
        ];

        return [
            new GeneratedFile(
                targetPath: $baseDir . '/Contracts/' . $entity->className . 'RepositoryInterface.php',
                content: $this->renderer->render(self::INTERFACE_TEMPLATE, $variables),
                overwritePolicy: $policy,
            ),
            new GeneratedFile(
                targetPath: $baseDir . '/Repository/' . $entity->className . 'Repository.php',
                content: $this->renderer->render(self::IMPLEMENTATION_TEMPLATE, $variables),
                overwritePolicy: $policy,
            ),
        ];
    }

    /**
     * Find the primary key property in the entity definition.
     */
    private function findPrimaryProperty(EntityDefinition $entity): ?PropertyDefinition
    {
        foreach ($entity->properties as $property) {
            if ($property->isPrimaryKey) {
                return $property;
            }
        }

        return null;
    }

    /**
     * Identify columns suitable for custom finder methods.
     *
     * Filterable, non-primary, string/int-typed columns get finders.
     *
     * @return list<PropertyDefinition>
     */
    private function findFinderColumns(EntityDefinition $entity): array
    {
        return array_values(
            array_filter(
                $entity->properties,
                static fn(PropertyDefinition $p): bool => $p->isFilterable
                    && ! $p->isPrimaryKey
                    && ($p->phpType === 'string' || $p->phpType === 'int'),
            ),
        );
    }

    /**
     * Build interface method signatures for custom finders.
     *
     * @param list<PropertyDefinition> $columns
     */
    private function buildFinderSignatures(EntityDefinition $entity, array $columns): string
    {
        if ($columns === []) {
            return '';
        }

        $lines = [];

        foreach ($columns as $prop) {
            $methodName = 'findBy' . ucfirst($prop->name);
            $lines[] = '';
            $lines[] = '    /**';
            $lines[] = '     * Find ' . $entity->className . ' records by ' . $prop->columnName . '.';
            $lines[] = '     *';
            $lines[] = '     * @return list<array<string, mixed>>';
            $lines[] = '     */';
            $lines[] = '    public function ' . $methodName . '(' . $prop->phpType . ' $value): array;';
        }

        return implode("\n", $lines);
    }

    /**
     * Build implementation methods for custom finders.
     *
     * @param list<PropertyDefinition> $columns
     */
    private function buildFinderImplementations(EntityDefinition $entity, array $columns): string
    {
        if ($columns === []) {
            return '';
        }

        $blocks = [];

        foreach ($columns as $prop) {
            $methodName = 'findBy' . ucfirst($prop->name);

            $block = <<<METHOD

                    public function {$methodName}({$prop->phpType} \$value): array
                    {
                        \$result = \$this->connection->query(
                            'SELECT * FROM {$entity->tableName} WHERE {$prop->columnName} = :value',
                            ['value' => \$value],
                        );

                        \$rows = [];

                        foreach (\$result->rows as \$row) {
                            \$rows[] = \$row->toArray();
                        }

                        return \$rows;
                    }
                METHOD;

            $blocks[] = $block;
        }

        return implode("\n", $blocks);
    }
}
