<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Binding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConnectionConfig;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\Orm\Attribute\Column;
use Pulsar\Extension\Orm\Attribute\Encrypted;
use Pulsar\Extension\Orm\Attribute\Id;
use Pulsar\Extension\Orm\Attribute\Relation;
use Pulsar\Extension\Orm\Attribute\SoftDelete;
use Pulsar\Extension\Orm\Attribute\Table;
use Pulsar\Extension\Orm\Attribute\TenantScoped;
use Pulsar\Extension\Orm\Attribute\TenantShared;
use Pulsar\Extension\Orm\Config\OrmConfig;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\RelationType;
use Pulsar\Extension\Orm\Features\Binding\OrmModelResolver;
use Pulsar\Extension\Orm\Features\Hydration\EntityDehydrator;
use Pulsar\Extension\Orm\Features\Hydration\EntityHydrator;
use Pulsar\Extension\Orm\Features\Metadata\CachedMetadataRegistry;
use Pulsar\Extension\Orm\Features\Metadata\MetadataCompiler;
use Pulsar\Extension\Orm\Features\Persistence\AuditingPersister;
use Pulsar\Extension\Orm\Features\Persistence\TransactionManager;
use Pulsar\Extension\Orm\Features\Tenancy\TenantColumnResolver;
use Pulsar\Extension\Orm\Gateway\EntityManager;
use Pulsar\Routing\Binding\ModelBindingConfig;
use Pulsar\Routing\Binding\ModelBindingException;
use Pulsar\Routing\Binding\ResolutionContext;

/**
 * Behavioural proof, against a real in-memory SQLite database, that route model
 * binding through the ORM cannot be talked into resolving a row the request is
 * not entitled to.
 *
 * Nothing here mocks the ORM: the metadata is compiled from the fixtures'
 * attributes, the SQL is compiled by the real builder, and the rows come back
 * through the real hydrator. A stubbed query builder would happily agree with
 * whatever the adapter asked it for, which is exactly the question under test.
 */
#[CoversClass(OrmModelResolver::class)]
final class OrmModelResolverTest extends TestCase
{
    private PdoConnection $connection;

    private EntityDehydrator $dehydrator;

    private EntityManager $entityManager;

    protected function setUp(): void
    {
        $this->connection = PdoConnection::fromConfig(new ConnectionConfig(
            name: 'model_binding',
            driver: Driver::SQLite,
            host: '',
            port: 0,
            database: ':memory:',
            username: '',
            password: '',
            charset: 'utf8mb4',
            collation: 'utf8mb4_unicode_ci',
            options: [],
        ));

        $this->createSchema();
        $this->seed();

        $registry = new CachedMetadataRegistry(new MetadataCompiler(OrmConfig::fromArray([])));
        $hydrator = new EntityHydrator($registry);
        $this->dehydrator = new EntityDehydrator($registry);

        $this->entityManager = new EntityManager(
            $this->connection,
            $registry,
            $hydrator,
            new AuditingPersister($this->connection, $registry, $this->dehydrator),
            new TransactionManager($this->connection),
        );
    }

    // -----------------------------------------------------------------
    // Resolving by key
    // -----------------------------------------------------------------

    #[Test]
    public function resolvesByPrimaryKey(): void
    {
        $user = $this->resolver()->resolve(BindingUser::class, 'id', 1, new ResolutionContext());

        self::assertInstanceOf(BindingUser::class, $user);
        self::assertSame('Ada', $user->name);
    }

    #[Test]
    public function resolvesByAnAllowedAlternateKey(): void
    {
        $user = $this->resolver()->resolve(BindingUser::class, 'slug', 'linus', new ResolutionContext());

        self::assertInstanceOf(BindingUser::class, $user);
        self::assertSame(2, $user->id);
    }

    #[Test]
    public function resolvesByTheIdAliasWhenThePrimaryKeyColumnIsNamedSomethingElse(): void
    {
        // Implicit binding always emits the key name 'id', so an entity whose
        // primary key column is `invoice_id` would be unbindable if 'id' were
        // only ever matched against column and property names.
        $invoice = $this->resolver()->resolve(
            BindingInvoice::class,
            'id',
            100,
            new ResolutionContext(tenantId: 'acme'),
        );

        self::assertInstanceOf(BindingInvoice::class, $invoice);
        self::assertSame('INV-100', $invoice->reference);
    }

    #[Test]
    public function aMissReturnsNullSoTheBinderCanRaiseItsOwnNotFound(): void
    {
        self::assertNull($this->resolver()->resolve(BindingUser::class, 'id', 9999, new ResolutionContext()));
    }

    #[Test]
    public function anUnmappedClassResolvesToNullRatherThanFailing(): void
    {
        // Implicit binding creates a binding for every class-typed controller
        // parameter whose name matches a route parameter, so plain DTOs reach
        // the resolver. A mapping error here would be a 500 on a 404 route.
        self::assertNull($this->resolver()->resolve(BindingNotAnEntity::class, 'id', 1, new ResolutionContext()));
    }

    // -----------------------------------------------------------------
    // Key names never reach SQL unvetted
    // -----------------------------------------------------------------

    #[Test]
    public function aKeyNameOutsideTheAllowListNeverReachesAQuery(): void
    {
        $resolver = $this->resolver(new ModelBindingConfig(allowedKeyNames: ['id']));

        // `slug` is a real, queryable column: only the allow-list stands between
        // the route and it, which is what makes this the allow-list's own test.
        $this->expectException(ModelBindingException::class);
        $this->expectExceptionCode(400);

        $resolver->resolve(BindingUser::class, 'slug', 'ada', new ResolutionContext());
    }

    #[Test]
    public function anAllowedButUnmappedKeyNameNeverReachesAQuery(): void
    {
        // `uuid` is in the default allow-list and is not a column on this table.
        // Reaching the database would raise DatabaseException ("no such column"),
        // so a ModelBindingException is proof the query was never compiled — and
        // the 400 names the misconfigured route instead of leaking a driver error.
        $this->expectException(ModelBindingException::class);
        $this->expectExceptionCode(400);

        $this->resolver()->resolve(BindingUser::class, 'uuid', 'x', new ResolutionContext());
    }

    #[Test]
    public function aDottedKeyNameCannotEscapeTheBaseAlias(): void
    {
        // where() leaves an already-qualified name alone, so a dotted key would
        // compile against a table the route never named. Even a host careless
        // enough to allow-list one is refused.
        $resolver = $this->resolver(new ModelBindingConfig(allowedKeyNames: ['sqlite_master.name']));

        $this->expectException(ModelBindingException::class);
        $this->expectExceptionCode(400);

        $resolver->resolve(BindingUser::class, 'sqlite_master.name', 'binding_users', new ResolutionContext());
    }

    #[Test]
    public function anEncryptedKeyColumnIsRefusedRatherThanSilentlyMatchingNothing(): void
    {
        // Comparing a ciphertext column to a plaintext route value can only ever
        // return no rows. Refusing the key reports the misconfiguration; allowing
        // it would report every request as "not found".
        $resolver = $this->resolver(new ModelBindingConfig(allowedKeyNames: ['id', 'taxCode']));

        $this->expectException(ModelBindingException::class);
        $this->expectExceptionCode(400);

        $resolver->resolve(BindingInvoice::class, 'taxCode', 'DE123', new ResolutionContext(tenantId: 'acme'));
    }

    // -----------------------------------------------------------------
    // Soft deletes
    // -----------------------------------------------------------------

    #[Test]
    public function aSoftDeletedRowDoesNotResolve(): void
    {
        self::assertNull(
            $this->resolver()->resolve(BindingPost::class, 'id', 30, new ResolutionContext()),
            'a trashed row must not be bindable by default',
        );
    }

    #[Test]
    public function aSoftDeletedRowResolvesOnlyWhenTheContextIncludesTrashed(): void
    {
        $post = $this->resolver()->resolve(
            BindingPost::class,
            'id',
            30,
            new ResolutionContext(includeTrashed: true),
        );

        self::assertInstanceOf(BindingPost::class, $post);
        self::assertSame(30, $post->id);
    }

    // -----------------------------------------------------------------
    // Tenant isolation
    // -----------------------------------------------------------------

    #[Test]
    public function aTenantScopedEntityDoesNotResolveAcrossTenants(): void
    {
        // Invoice 101 belongs to globex. Its primary key is guessable; the tenant
        // predicate is the only thing between acme and another tenant's invoice.
        self::assertNull(
            $this->resolver()->resolve(BindingInvoice::class, 'id', 101, new ResolutionContext(tenantId: 'acme')),
            'a foreign tenant row must not be reachable by id',
        );

        // Control: the same row IS reachable by the tenant that owns it, so the
        // null above is the tenant filter and not a broken fixture.
        self::assertInstanceOf(
            BindingInvoice::class,
            $this->resolver()->resolve(BindingInvoice::class, 'id', 101, new ResolutionContext(tenantId: 'globex')),
        );
    }

    #[Test]
    public function aTenantScopedEntityResolvesNothingWhenNoTenantIsInContext(): void
    {
        // No ambient TenantScope is wired here — the default install for every
        // Pulsar app — so the ORM's own applier contributes no predicate. Without
        // a tenant the honest answer is nothing, never every tenant's rows.
        self::assertNull($this->resolver()->resolve(BindingInvoice::class, 'id', 100, new ResolutionContext()));
        self::assertNull($this->resolver()->resolve(BindingInvoice::class, 'id', 101, new ResolutionContext()));
    }

    #[Test]
    public function aTenantSharedEntityStillResolvesWithoutATenant(): void
    {
        // #[TenantShared] beats #[TenantScoped]: a shared lookup table is
        // legitimately cross-tenant and must not be caught by the rule above.
        $invoice = $this->resolver()->resolve(BindingSharedInvoice::class, 'id', 100, new ResolutionContext());

        self::assertInstanceOf(BindingSharedInvoice::class, $invoice);
        self::assertSame('INV-100', $invoice->reference);
    }

    // -----------------------------------------------------------------
    // Scoped binding — the authorization boundary
    // -----------------------------------------------------------------

    #[Test]
    public function resolveScopedRefusesAChildBelongingToAnotherParentBecauseThatIsAnAuthorizationBypass(): void
    {
        // This is the whole point of a scoped binding. Post 20 belongs to user 2.
        // Asked for it under user 1 — the route /users/1/posts/20, where user 1 is
        // the caller's own record — the resolver must return nothing. Falling back
        // to an unscoped lookup would hand one user another user's row while every
        // layer above still believes the parent constrained the request, which is
        // the /orgs/{mine}/invoices/{yours} bypass in full.
        $parent = $this->resolver()->resolve(BindingUser::class, 'id', 1, new ResolutionContext());
        self::assertInstanceOf(BindingUser::class, $parent);

        self::assertNull(
            $this->resolver()->resolveScoped(
                BindingPost::class,
                'id',
                20,
                $parent,
                'posts',
                new ResolutionContext(),
            ),
            'a child of another parent must not resolve: that is an authorization bypass, not a miss',
        );

        // Control: the same call for user 2's own parent does resolve post 20, so
        // the null above is the parent constraint and not an unrelated failure.
        $owner = $this->resolver()->resolve(BindingUser::class, 'id', 2, new ResolutionContext());
        self::assertInstanceOf(BindingUser::class, $owner);
        self::assertInstanceOf(
            BindingPost::class,
            $this->resolver()->resolveScoped(BindingPost::class, 'id', 20, $owner, 'posts', new ResolutionContext()),
        );
    }

    #[Test]
    public function resolveScopedReturnsTheChildOfTheGivenParent(): void
    {
        $parent = $this->resolver()->resolve(BindingUser::class, 'id', 1, new ResolutionContext());
        self::assertInstanceOf(BindingUser::class, $parent);

        $post = $this->resolver()->resolveScoped(
            BindingPost::class,
            'id',
            10,
            $parent,
            'posts',
            new ResolutionContext(),
        );

        self::assertInstanceOf(BindingPost::class, $post);
        self::assertSame('First', $post->title);
    }

    #[Test]
    public function resolveScopedStillHonoursTheSoftDeleteFilter(): void
    {
        // Post 30 does belong to user 1; it is trashed, so the parent constraint
        // must not be read as permission to skip the other filters.
        $parent = $this->resolver()->resolve(BindingUser::class, 'id', 1, new ResolutionContext());
        self::assertInstanceOf(BindingUser::class, $parent);

        self::assertNull(
            $this->resolver()->resolveScoped(BindingPost::class, 'id', 30, $parent, 'posts', new ResolutionContext()),
        );
    }

    #[Test]
    public function resolveScopedFailsClosedOnAnUnknownRelation(): void
    {
        $parent = $this->resolver()->resolve(BindingUser::class, 'id', 1, new ResolutionContext());
        self::assertInstanceOf(BindingUser::class, $parent);

        self::assertNull(
            $this->resolver()->resolveScoped(BindingPost::class, 'id', 10, $parent, 'drafts', new ResolutionContext()),
            'an unknown relation must resolve nothing, never degrade to an unscoped lookup',
        );
    }

    #[Test]
    public function resolveScopedFailsClosedWhenTheRelationTargetsAnotherEntity(): void
    {
        // The binder tracks the parent positionally and records no parent class,
        // so a relation whose target is not the requested class is the only
        // symptom of a mismatched chain the resolver can see.
        $parent = $this->resolver()->resolve(BindingUser::class, 'id', 1, new ResolutionContext());
        self::assertInstanceOf(BindingUser::class, $parent);

        self::assertNull(
            $this->resolver()->resolveScoped(BindingUser::class, 'id', 2, $parent, 'posts', new ResolutionContext()),
        );
    }

    #[Test]
    public function resolveScopedFailsClosedOnARelationTypeItCannotConstrain(): void
    {
        // BelongsTo stores its foreign key as a property on the owner rather than
        // a column on the target, so the child query cannot be constrained from
        // this side. Unsupported must mean nothing, not everything.
        $post = $this->resolver()->resolve(BindingPost::class, 'id', 10, new ResolutionContext());
        self::assertInstanceOf(BindingPost::class, $post);

        self::assertNull(
            $this->resolver()->resolveScoped(BindingUser::class, 'id', 1, $post, 'author', new ResolutionContext()),
        );
    }

    #[Test]
    public function resolveScopedThroughAPivotRequiresAMembershipRow(): void
    {
        $team = $this->resolver()->resolve(BindingTeam::class, 'id', 1, new ResolutionContext());
        self::assertInstanceOf(BindingTeam::class, $team);

        $member = $this->resolver()->resolveScoped(
            BindingMember::class,
            'id',
            5,
            $team,
            'members',
            new ResolutionContext(),
        );

        self::assertInstanceOf(BindingMember::class, $member);
        self::assertSame('Grace', $member->name);

        // Member 6 exists and is a member of team 2 only.
        self::assertNull(
            $this->resolver()->resolveScoped(BindingMember::class, 'id', 6, $team, 'members', new ResolutionContext()),
            'a member of another team must not resolve under this team',
        );
    }

    #[Test]
    public function resolveScopedConstrainsByTheRelationsDeclaredLocalKey(): void
    {
        // `#[Relation(localKey: 'code')]` says the child's foreign key points at
        // the parent's `code` column, not at its primary key. Reading the
        // primary key regardless queries the right column for the wrong value,
        // and the rows carrying that value belong to somebody else: org 1's
        // key is 1, and the ledger whose org_code is '1' is org 2's.
        $org = $this->resolver()->resolve(BindingOrg::class, 'id', 1, new ResolutionContext());
        self::assertInstanceOf(BindingOrg::class, $org);
        self::assertSame('2000', $org->code);

        self::assertNull(
            $this->resolver()->resolveScoped(
                BindingLedger::class,
                'id',
                200,
                $org,
                'ledgers',
                new ResolutionContext(),
            ),
            'ledger 200 belongs to org 2: constraining by the parent primary key hands it to org 1',
        );

        // Control: org 1's own ledger, found through its code and not its id.
        $ledger = $this->resolver()->resolveScoped(
            BindingLedger::class,
            'id',
            201,
            $org,
            'ledgers',
            new ResolutionContext(),
        );

        self::assertInstanceOf(BindingLedger::class, $ledger);
        self::assertSame('Org 1 ledger', $ledger->label);
    }

    #[Test]
    public function resolveScopedFailsClosedWhenTheLocalKeyNamesNoColumn(): void
    {
        // A local key the parent does not map cannot be read, and a constraint
        // that cannot be built must drop the resolution, not the constraint.
        $org = $this->resolver()->resolve(BindingOrg::class, 'id', 1, new ResolutionContext());
        self::assertInstanceOf(BindingOrg::class, $org);

        self::assertNull(
            $this->resolver()->resolveScoped(
                BindingLedger::class,
                'id',
                201,
                $org,
                'strayLedgers',
                new ResolutionContext(),
            ),
        );
    }

    #[Test]
    public function resolveScopedAppliesTheTenantFilterToTheChild(): void
    {
        $team = $this->resolver()->resolve(BindingTeam::class, 'id', 1, new ResolutionContext());
        self::assertInstanceOf(BindingTeam::class, $team);

        // The relation is satisfied, but the child entity is tenant-scoped and no
        // tenant is in context: the parent constraint does not license the read.
        self::assertNull(
            $this->resolver()->resolveScoped(
                BindingInvoice::class,
                'id',
                100,
                $team,
                'invoices',
                new ResolutionContext(),
            ),
        );

        self::assertInstanceOf(
            BindingInvoice::class,
            $this->resolver()->resolveScoped(
                BindingInvoice::class,
                'id',
                100,
                $team,
                'invoices',
                new ResolutionContext(tenantId: 'acme'),
            ),
        );
    }

    // -----------------------------------------------------------------
    // Fixture plumbing
    // -----------------------------------------------------------------

    private function resolver(?ModelBindingConfig $config = null): OrmModelResolver
    {
        return new OrmModelResolver(
            $this->entityManager,
            new TenantColumnResolver(OrmConfig::fromArray([])),
            $this->dehydrator,
            $config ?? new ModelBindingConfig(),
        );
    }

    private function createSchema(): void
    {
        $this->connection->execute(
            'CREATE TABLE binding_users (id INTEGER PRIMARY KEY, slug TEXT NOT NULL, name TEXT NOT NULL)',
        );
        $this->connection->execute(
            'CREATE TABLE binding_posts ('
            . 'id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, title TEXT NOT NULL, deleted_at TEXT NULL)',
        );
        $this->connection->execute(
            'CREATE TABLE binding_invoices ('
            . 'invoice_id INTEGER PRIMARY KEY, team_id INTEGER NOT NULL, reference TEXT NOT NULL, '
            . 'tax_code TEXT NOT NULL, tenant_id TEXT NOT NULL)',
        );
        $this->connection->execute('CREATE TABLE binding_teams (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $this->connection->execute('CREATE TABLE binding_members (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $this->connection->execute(
            'CREATE TABLE binding_team_member (team_id INTEGER NOT NULL, member_id INTEGER NOT NULL)',
        );
        $this->connection->execute(
            'CREATE TABLE binding_orgs (id INTEGER PRIMARY KEY, code TEXT NOT NULL)',
        );
        $this->connection->execute(
            'CREATE TABLE binding_ledgers (id INTEGER PRIMARY KEY, org_code TEXT NOT NULL, label TEXT NOT NULL)',
        );
    }

    private function seed(): void
    {
        $this->connection->execute(
            'INSERT INTO binding_users (id, slug, name) VALUES (1, \'ada\', \'Ada\'), (2, \'linus\', \'Linus\')',
        );
        $this->connection->execute(
            'INSERT INTO binding_posts (id, user_id, title, deleted_at) VALUES '
            . '(10, 1, \'First\', NULL), (20, 2, \'Second\', NULL), (30, 1, \'Trashed\', \'2026-01-01 00:00:00\')',
        );
        $this->connection->execute(
            'INSERT INTO binding_invoices (invoice_id, team_id, reference, tax_code, tenant_id) VALUES '
            . '(100, 1, \'INV-100\', \'DE123\', \'acme\'), (101, 1, \'INV-101\', \'DE456\', \'globex\')',
        );
        $this->connection->execute(
            'INSERT INTO binding_teams (id, name) VALUES (1, \'Core\'), (2, \'Ops\')',
        );
        $this->connection->execute(
            'INSERT INTO binding_members (id, name) VALUES (5, \'Grace\'), (6, \'Alan\')',
        );
        $this->connection->execute(
            'INSERT INTO binding_team_member (team_id, member_id) VALUES (1, 5), (2, 6)',
        );
        // Codes are crossed against the ids on purpose: org 1's code is '2000',
        // while org 2's code is '1' — the very string org 1's primary key
        // stringifies to. A resolver that reads the primary key where the
        // relation declares `code` therefore lands squarely on org 2's rows
        // instead of resolving nothing.
        $this->connection->execute(
            'INSERT INTO binding_orgs (id, code) VALUES (1, \'2000\'), (2, \'1\')',
        );
        $this->connection->execute(
            'INSERT INTO binding_ledgers (id, org_code, label) VALUES '
            . '(200, \'1\', \'Org 2 ledger\'), (201, \'2000\', \'Org 1 ledger\')',
        );
    }
}

/**
 * @internal
 */
#[Table(name: 'binding_users')]
final class BindingUser
{
    #[Id]
    #[Column(type: ColumnType::Integer)]
    public int $id = 0;

    #[Column(type: ColumnType::String)]
    public string $slug = '';

    #[Column(type: ColumnType::String)]
    public string $name = '';

    /** @var list<BindingPost> */
    #[Relation(type: RelationType::HasMany, target: BindingPost::class, foreignKey: 'user_id')]
    public array $posts = [];
}

/**
 * @internal
 */
#[Table(name: 'binding_posts')]
#[SoftDelete]
final class BindingPost
{
    #[Id]
    #[Column(type: ColumnType::Integer)]
    public int $id = 0;

    #[Column(name: 'user_id', type: ColumnType::Integer)]
    public int $userId = 0;

    #[Column(type: ColumnType::String)]
    public string $title = '';

    #[Relation(type: RelationType::BelongsTo, target: BindingUser::class, foreignKey: 'userId')]
    public ?BindingUser $author = null;
}

/**
 * Tenant-scoped, with a primary key column that is not called `id` and an
 * encrypted column with no blind index — the two shapes that decide whether a
 * route key is usable at all.
 *
 * @internal
 */
#[Table(name: 'binding_invoices')]
#[TenantScoped]
final class BindingInvoice
{
    #[Id]
    #[Column(name: 'invoice_id', type: ColumnType::Integer)]
    public int $invoiceId = 0;

    #[Column(name: 'reference', type: ColumnType::String)]
    public string $reference = '';

    #[Column(name: 'tax_code', type: ColumnType::String)]
    #[Encrypted]
    public string $taxCode = '';
}

/**
 * The same physical rows, declared shared across tenants.
 *
 * @internal
 */
#[Table(name: 'binding_invoices')]
#[TenantScoped]
#[TenantShared]
final class BindingSharedInvoice
{
    #[Id]
    #[Column(name: 'invoice_id', type: ColumnType::Integer)]
    public int $invoiceId = 0;

    #[Column(name: 'reference', type: ColumnType::String)]
    public string $reference = '';
}

/**
 * @internal
 */
#[Table(name: 'binding_teams')]
final class BindingTeam
{
    #[Id]
    #[Column(type: ColumnType::Integer)]
    public int $id = 0;

    #[Column(type: ColumnType::String)]
    public string $name = '';

    /** @var list<BindingMember> */
    #[Relation(
        type: RelationType::BelongsToMany,
        target: BindingMember::class,
        pivotTable: 'binding_team_member',
        pivotForeignKey: 'team_id',
        pivotRelatedKey: 'member_id',
    )]
    public array $members = [];

    /** @var list<BindingInvoice> */
    #[Relation(type: RelationType::HasMany, target: BindingInvoice::class, foreignKey: 'team_id')]
    public array $invoices = [];
}

/**
 * @internal
 */
#[Table(name: 'binding_members')]
final class BindingMember
{
    #[Id]
    #[Column(type: ColumnType::Integer)]
    public int $id = 0;

    #[Column(type: ColumnType::String)]
    public string $name = '';
}

/**
 * Parent of a relation keyed on a column that is not the primary key.
 *
 * @internal
 */
#[Table(name: 'binding_orgs')]
final class BindingOrg
{
    #[Id]
    #[Column(type: ColumnType::Integer)]
    public int $id = 0;

    #[Column(type: ColumnType::String)]
    public string $code = '';

    /** @var list<BindingLedger> */
    #[Relation(
        type: RelationType::HasMany,
        target: BindingLedger::class,
        foreignKey: 'org_code',
        localKey: 'code',
    )]
    public array $ledgers = [];

    /**
     * A local key the entity does not map at all.
     *
     * @var list<BindingLedger>
     */
    #[Relation(
        type: RelationType::HasMany,
        target: BindingLedger::class,
        foreignKey: 'org_code',
        localKey: 'no_such_column',
    )]
    public array $strayLedgers = [];
}

/**
 * @internal
 */
#[Table(name: 'binding_ledgers')]
final class BindingLedger
{
    #[Id]
    #[Column(type: ColumnType::Integer)]
    public int $id = 0;

    #[Column(name: 'org_code', type: ColumnType::String)]
    public string $orgCode = '';

    #[Column(type: ColumnType::String)]
    public string $label = '';
}

/**
 * A controller may type-hint anything; implicit binding does not check that the
 * type is an entity before handing it to a resolver.
 *
 * @internal
 */
final class BindingNotAnEntity
{
    public int $id = 0;
}
