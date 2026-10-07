<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Binding;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Domain\IdentifierValidator;
use Pulsar\Extension\Orm\Domain\RelationMetadata;
use Pulsar\Extension\Orm\Domain\RelationType;
use Pulsar\Extension\Orm\Exception\MappingException;
use Pulsar\Extension\Orm\Features\Hydration\EntityDehydrator;
use Pulsar\Extension\Orm\Features\Query\SelectBuilder;
use Pulsar\Extension\Orm\Features\Tenancy\TenantColumnResolver;
use Pulsar\Extension\Orm\Gateway\EntityManager;
use Pulsar\Routing\Binding\Contract\ModelResolverPort;
use Pulsar\Routing\Binding\ModelBindingConfig;
use Pulsar\Routing\Binding\ModelBindingException;
use Pulsar\Routing\Binding\ResolutionContext;

use function in_array;
use function is_int;
use function is_string;

/**
 * ORM-backed implementation of the routing module's model resolver port.
 *
 * The routing module owns the port; entity resolution is a persistence
 * concern, so the adapter lives here (ADR-0002) and routing keeps its
 * one-way dependency on the interface.
 *
 * Four properties hold regardless of how a route is declared, because the
 * key name reaching this class comes from the request and nothing upstream
 * filters it — {@see ModelBindingConfig::$allowedKeyNames} has no other
 * enforcement point, and {@see SelectBuilder::where()} escapes an identifier
 * but never checks that it names a real column:
 *
 *  1. A key name is usable only when the configured allow-list contains it
 *     AND the entity's own metadata maps it to a column. The string that
 *     reaches SQL is always {@see ColumnMetadata::$columnName} from that
 *     mapping, never the route-supplied text.
 *  2. A tenant-scoped entity is filtered by {@see ResolutionContext::$tenantId}
 *     here, not by {@see \Pulsar\Extension\Orm\Features\Tenancy\TenantScopeApplier},
 *     which fails open and is unbound unless the host provides a TenantScope.
 *     No tenant in context resolves nothing rather than everything.
 *  3. Soft-deleted rows stay excluded unless the context opts in; the entity
 *     builder is default-deny already, so the opt-in is the only action taken.
 *  4. A scoped binding constrains the child by the parent's own relation, on
 *     the parent column that relation declares — {@see RelationMetadata::$localKey},
 *     not the primary key it merely defaults to. A relation this adapter
 *     cannot constrain resolves nothing, and so does one whose local key
 *     cannot be read: degrading to an unscoped lookup, or to a constraint
 *     built from the wrong parent column, would both let
 *     /orgs/{mine}/invoices/{yours} return yours.
 *
 * A miss returns null, which {@see \Pulsar\Routing\Binding\ModelBinder} turns
 * into a 404. Only an unusable key name throws, as a 400 the binding
 * middleware already maps; anything else escaping here would leave the
 * middleware, which catches ModelBindingException alone.
 */
#[Internal(reason: 'Wired through ModelResolverPort; hosts depend on the port, not on this adapter')]
final readonly class OrmModelResolver implements ModelResolverPort
{
    /**
     * The key name implicit binding always emits. It means "the primary key",
     * whatever that column is physically called — without this, an entity
     * whose PK column is not literally `id` could never be bound implicitly,
     * since {@see \Pulsar\Routing\Binding\BindingResolver::resolveImplicit()}
     * hard-codes the name.
     */
    private const string PRIMARY_KEY_ALIAS = 'id';

    public function __construct(
        private EntityManager $entityManager,
        private TenantColumnResolver $tenantColumnResolver,
        private EntityDehydrator $dehydrator,
        private ModelBindingConfig $bindingConfig,
    ) {}

    /**
     * @param class-string $modelClass
     *
     * @throws ModelBindingException When the key name may not be used to query this model.
     */
    #[Override]
    public function resolve(string $modelClass, string $keyName, string|int $keyValue, ResolutionContext $context): ?object
    {
        $metadata = $this->metadataFor($modelClass);
        if ($metadata === null) {
            return null;
        }

        $builder = $this->queryFor($modelClass, $metadata, $keyName, $keyValue, $context);

        return $builder?->firstEntity();
    }

    /**
     * @param class-string $modelClass
     *
     * @throws ModelBindingException When the key name may not be used to query this model.
     */
    #[Override]
    public function resolveScoped(
        string $modelClass,
        string $keyName,
        string|int $keyValue,
        object $parent,
        string $relation,
        ResolutionContext $context,
    ): ?object {
        $childMetadata = $this->metadataFor($modelClass);
        $parentMetadata = $this->metadataFor($parent::class);
        if ($childMetadata === null || $parentMetadata === null) {
            return null;
        }

        // The relation is declared on the PARENT and names the child collection.
        // BindingMeta records which route PARAMETER holds the parent, so the
        // object arriving here is the one the path named rather than whatever
        // resolved most recently — but it records no expected parent CLASS, so
        // this pair of checks is still the only place a parent/child type
        // mismatch can be caught at all.
        $relationMetadata = $parentMetadata->relations[$relation] ?? null;
        if ($relationMetadata === null || $relationMetadata->targetEntity !== $modelClass) {
            return null;
        }

        $builder = $this->queryFor($modelClass, $childMetadata, $keyName, $keyValue, $context);
        if ($builder === null) {
            return null;
        }

        $parentKey = $this->parentKeyFor($parentMetadata, $relationMetadata, $parent);
        if ($parentKey === null) {
            return null;
        }

        // Enumerated rather than defaulted: adding a relation type to the ORM
        // must break this match, not silently gain an unconstrained arm.
        return match ($relationMetadata->type) {
            RelationType::HasOne,
            RelationType::HasMany => $this->scopedByForeignKey($builder, $childMetadata, $relationMetadata, $parentKey),
            RelationType::BelongsToMany => $this->scopedByPivot($builder, $relationMetadata, $parentKey),
            // BelongsTo reads its foreign key as a PHP property on the owner
            // rather than a column on the target, and the through/morph types
            // need a second mapped hop. None of them can be constrained from
            // the child side with what RelationMetadata carries, so they
            // resolve nothing instead of resolving unscoped.
            RelationType::BelongsTo,
            RelationType::HasManyThrough,
            RelationType::MorphTo,
            RelationType::MorphMany,
            RelationType::MorphToMany => null,
        };
    }

    /**
     * Read the parent-side value a scoped child is constrained by.
     *
     * {@see RelationMetadata::$localKey} names the column on the parent that
     * the child's foreign key points at — `#[Relation(localKey: ...)]`, which
     * defaults to the primary key but need not be it. Reading the primary key
     * regardless would query the right column for the wrong value on any
     * relation that declares one, and the child rows carrying that value
     * belong to a different parent: the query still looks scoped and is not.
     *
     * The default `id` is resolved through {@see PRIMARY_KEY_ALIAS} for the
     * same reason a route key is, so an entity whose primary key column is
     * named something else keeps working without declaring a localKey.
     *
     * Fails closed — null, a 404 — on a local key that names no column, on an
     * encrypted one (ciphertext compares against nothing), and on a value that
     * is not a scalar key, rather than letting the constraint be dropped.
     */
    private function parentKeyFor(
        EntityMetadata $parentMetadata,
        RelationMetadata $relation,
        object $parent,
    ): string|int|null {
        $column = $parentMetadata->columnByName($relation->localKey)
            ?? $parentMetadata->columnByProperty($relation->localKey)
            ?? ($relation->localKey === self::PRIMARY_KEY_ALIAS ? $parentMetadata->primaryKey : null);

        if ($column === null || $column->encrypted) {
            return null;
        }

        /** @var mixed $value */
        $value = $this->dehydrator->extractColumnValue($parent, $column);

        return is_string($value) || is_int($value) ? $value : null;
    }

    /**
     * Build the entity query carrying every context-derived filter.
     *
     * Returns null when the context forbids resolving this entity at all,
     * which is a "not found" for the caller rather than an error.
     *
     * @param class-string $modelClass
     *
     * @throws ModelBindingException When the key name may not be used to query this model.
     */
    private function queryFor(
        string $modelClass,
        EntityMetadata $metadata,
        string $keyName,
        string|int $keyValue,
        ResolutionContext $context,
    ): ?SelectBuilder {
        $keyColumn = $this->keyColumnFor($metadata, $keyName);

        // query() — never find(). find() short-circuits on the identity map,
        // keyed on class and primary key alone, so in a persistent worker it
        // would hand back a row loaded under a different tenant without ever
        // reaching the filters below.
        $builder = $this->entityManager->query($modelClass);

        if (!$this->applyTenantScope($builder, $metadata, $context)) {
            return null;
        }

        if ($context->includeTrashed) {
            $builder->withTrashed();
        }

        $builder->where($keyColumn, $keyValue);

        return $builder;
    }

    /**
     * Resolve a route-supplied key name to the column it may query.
     *
     * @throws ModelBindingException When the key name may not be used to query this model.
     */
    private function keyColumnFor(EntityMetadata $metadata, string $keyName): string
    {
        if (!in_array($keyName, $this->bindingConfig->allowedKeyNames, true)) {
            throw ModelBindingException::invalidKeyName($keyName, $metadata->entityClass);
        }

        // Belt and braces over the mapping lookup below: a host is free to put
        // anything in the allow-list, and where() leaves a dotted name
        // unqualified, which would step outside the base alias entirely.
        if (!IdentifierValidator::isValid($keyName)) {
            throw ModelBindingException::invalidKeyName($keyName, $metadata->entityClass);
        }

        $column = $metadata->columnByName($keyName)
            ?? $metadata->columnByProperty($keyName)
            ?? ($keyName === self::PRIMARY_KEY_ALIAS ? $metadata->primaryKey : null);

        if ($column === null) {
            throw ModelBindingException::invalidKeyName($keyName, $metadata->entityClass);
        }

        // An encrypted column stores ciphertext, so comparing it to a plaintext
        // route value matches nothing. Refusing the key names the misconfigured
        // route; allowing it would report every request as "not found" and look
        // like missing data. Binding through a blind index needs the column
        // encryptor and a deliberate hashing step, which this adapter does not do.
        if ($column->encrypted) {
            throw ModelBindingException::invalidKeyName($keyName, $metadata->entityClass);
        }

        return $column->columnName;
    }

    /**
     * Constrain the query to the tenant in context.
     *
     * Returns false when the entity is tenant-scoped but the context carries
     * no tenant: resolving every tenant's rows is the one outcome that must
     * never happen, so the binding resolves nothing instead.
     */
    private function applyTenantScope(SelectBuilder $builder, EntityMetadata $metadata, ResolutionContext $context): bool
    {
        if (!$metadata->isTenantScoped || $metadata->isTenantShared) {
            return true;
        }

        // Deliberately not passed through the mapped-column gate that guards the
        // route key: the tenant column comes from #[TenantScoped] or ORM config
        // and is routinely absent from the property scan, so requiring a mapping
        // would drop the predicate on exactly the entities that need it.
        $column = $this->tenantColumnResolver->resolve($metadata);
        if ($column === null || !IdentifierValidator::isValid($column)) {
            return false;
        }

        if ($context->tenantId === null) {
            return false;
        }

        $builder->where($column, $context->tenantId);

        return true;
    }

    /**
     * Constrain a HasOne/HasMany child by the foreign key it carries.
     */
    private function scopedByForeignKey(
        SelectBuilder $builder,
        EntityMetadata $childMetadata,
        RelationMetadata $relation,
        string|int $parentKey,
    ): ?object {
        // The declared foreign key is a column on the child table. It is usually
        // mapped as a property too, but need not be — the same way a tenant
        // column need not be — so an unmapped name is used as written once its
        // shape is checked, since where() does no validation of its own.
        $mapped = $childMetadata->columnByName($relation->foreignKey)
            ?? $childMetadata->columnByProperty($relation->foreignKey);
        $column = $mapped === null ? $relation->foreignKey : $mapped->columnName;

        if (!IdentifierValidator::isValid($column)) {
            return null;
        }

        $builder->where($column, $parentKey);

        return $builder->firstEntity();
    }

    /**
     * Constrain a BelongsToMany child by proven membership of the pivot row.
     */
    private function scopedByPivot(SelectBuilder $builder, RelationMetadata $relation, string|int $parentKey): ?object
    {
        $pivotTable = $relation->pivotTable;
        $parentColumn = $relation->pivotForeignKey;
        $relatedColumn = $relation->pivotRelatedKey;

        if ($pivotTable === null || $parentColumn === null || $relatedColumn === null) {
            return null;
        }

        if (
            !IdentifierValidator::isValid($pivotTable)
            || !IdentifierValidator::isValid($parentColumn)
            || !IdentifierValidator::isValid($relatedColumn)
        ) {
            return null;
        }

        $model = $builder->firstEntity();
        if ($model === null) {
            return null;
        }

        // Membership is probed with both pivot keys bound, rather than widening
        // the child query with every related id the parent owns: the probe stays
        // one indexed row whatever the parent's fan-out, and whereIn() rejects
        // the empty list a childless parent would produce.
        $membership = $this->entityManager->rawQuery()
            ->from($pivotTable)
            ->where($parentColumn, $parentKey)
            ->where($relatedColumn, $this->dehydrator->extractId($model))
            ->first();

        return $membership === null ? null : $model;
    }

    /**
     * Metadata for a class, or null when the class is not a mapped entity.
     *
     * Implicit binding creates a BindingMeta for every class-typed controller
     * parameter whose name matches a route parameter — DTOs and value objects
     * included — so an unmapped class is an ordinary miss, not a 500.
     *
     * @param class-string $entityClass
     */
    private function metadataFor(string $entityClass): ?EntityMetadata
    {
        $registry = $this->entityManager->metadata();

        if (!$registry->has($entityClass)) {
            return null;
        }

        try {
            return $registry->get($entityClass);
        } catch (MappingException) {
            // has() and get() are separate contract methods; a registry whose
            // two answers disagree must not turn a route into a server error.
            return null;
        }
    }
}
