<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark;

use PhpBench\Attributes\AfterMethods;
use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;
use Pulsar\Auth\Authorization\Gate;
use Pulsar\Auth\Authorization\InMemoryRoleRegistry;
use Pulsar\Auth\Authorization\Permission;
use Pulsar\Auth\Authorization\PolicyContext;
use Pulsar\Auth\Authorization\Role;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Internal\Authorization\BufferedAuthorizationDecisionSink;
use Pulsar\Config\AuthorizationConfig;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditSinkInterface;

use function random_bytes;

/**
 * Benchmarks for authorization and security primitives.
 *
 * Covers value object creation (Permission, Role, PolicyContext),
 * and Gate authorization checks for allowed and denied cases.
 *
 * `benchGateAllowsRecordingDecisions` is the one that would have caught the
 * regression this file used to miss. The other Gate subjects build a Gate that
 * records nothing, so the wiring that put the application's event dispatcher on
 * the decision path -- 4 microseconds to 58, and rising with every listener the
 * application had registered -- passed them untouched. A Gate wired the way
 * `AuthWiring` wires it is the one worth a budget.
 *
 * It has to be wired with what `AuthWiring` actually passes, too. It was built
 * with a buffer capacity of 1_000_000 where the framework shipped 64, so the
 * subject measured a sink that never wrote and the shipped one chained
 * sixty-four entries inside every sixty-fourth decision. The capacity now comes
 * from `AuthorizationConfig::DEFAULT_DECISION_AUDIT_BUFFER`, which is the value
 * `config/security.php` ships, so the two cannot drift apart again.
 */
#[BeforeMethods('setUp')]
#[Revs(1000)]
#[Iterations(5)]
#[Warmup(1)]
final class AuthorizationBench
{
    private Gate $gate;
    private Gate $recordingGate;
    private Gate $writeThroughGate;
    private BufferedAuthorizationDecisionSink $decisionSink;
    private Identity $allowedIdentity;
    private Identity $deniedIdentity;

    public function setUp(): void
    {
        $registry = new InMemoryRoleRegistry();

        $adminRole = new Role('admin', [
            new Permission('users.create'),
            new Permission('users.read'),
            new Permission('users.update'),
            new Permission('users.delete'),
            new Permission('reports.view'),
        ]);

        $viewerRole = new Role('viewer', [
            new Permission('reports.view'),
        ]);

        $registry->register($adminRole);
        $registry->register($viewerRole);

        $this->gate = new Gate($registry, ['super_admin']);

        // The shipped capacity, read from the config default rather than
        // written down here, so the subject cannot drift away from what
        // deployments run. It used to be 1_000_000 — a number no
        // `config/security.php` ever contained — which meant the subject
        // measured a sink that could not reach its capacity while the shipped
        // one flushed sixty-four chained entries from inside every sixty-fourth
        // decision. A benchmark that holds the budget for a configuration
        // nobody deploys guards nothing.
        $this->decisionSink = new BufferedAuthorizationDecisionSink(
            auditLogger: new AuditLogger(new DiscardingAuditSink(), random_bytes(32)),
            capacity: AuthorizationConfig::DEFAULT_DECISION_AUDIT_BUFFER,
        );

        $this->recordingGate = new Gate($registry, ['super_admin'], $this->decisionSink);

        $this->writeThroughGate = new Gate(
            $registry,
            ['super_admin'],
            new BufferedAuthorizationDecisionSink(
                auditLogger: new AuditLogger(new DiscardingAuditSink(), random_bytes(32)),
                capacity: 1,
            ),
        );

        $this->allowedIdentity = new Identity(
            id: 'user-1',
            displayName: 'Admin User',
            roles: ['admin'],
        );

        $this->deniedIdentity = new Identity(
            id: 'user-2',
            displayName: 'Viewer User',
            roles: ['viewer'],
        );
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 5 microseconds')]
    public function benchPermissionCreation(): void
    {
        $_ = new Permission('users.create');
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 5 microseconds')]
    public function benchRoleCreation(): void
    {
        $_ = new Role('editor', [
            new Permission('posts.create'),
            new Permission('posts.update'),
        ]);
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 5 microseconds')]
    public function benchPolicyContextCreation(): void
    {
        $_ = new PolicyContext(
            permission: 'users.create',
            resource: 'user:42',
            attributes: ['department' => 'engineering', 'level' => 5],
        );
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 50 microseconds')]
    public function benchGateAllows(): void
    {
        $this->gate->allows($this->allowedIdentity, 'users.create');
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 50 microseconds')]
    public function benchGateDenies(): void
    {
        $this->gate->denies($this->deniedIdentity, 'users.create');
    }

    /**
     * What one decision costs when it is recorded the way the framework records
     * it: captured on the decision path, written at a drain point.
     *
     * One iteration is one unit of work. `flushDecisions()` is the drain the
     * kernel performs on terminate — after the response, outside every decision
     * — so it runs between iterations and not inside one. The revolution count
     * is set below the shipped capacity on purpose: an iteration that ran past
     * it would be measuring the ceiling's one-entry-per-decision fallback,
     * which is a deployment defect and has its own budget in
     * `benchGateAllowsWithSynchronousAudit`.
     *
     * The budget is deliberately far below what a chained audit write costs
     * (roughly fifty microseconds, see the subject below), so a change that puts the write
     * -- or an event dispatcher, or anything else the application supplies --
     * back inside `allows()` fails here rather than in production.
     */
    #[Subject]
    #[Revs(500)]
    #[AfterMethods('flushDecisions')]
    #[Assert('mode(variant.time.avg) < 20 microseconds')]
    public function benchGateAllowsRecordingDecisions(): void
    {
        $this->recordingGate->allows($this->allowedIdentity, 'users.create');
    }

    /**
     * The same decision with `decision_audit_buffer: 1` -- the configuration a
     * deployment chooses when it will not accept a decision being buffered at
     * the moment of a hard process death.
     *
     * The number is the honest total: chaining an audit entry costs what it
     * costs, and this subject is where that shows. The budget is loose because
     * the cost is dominated by the audit logger rather than by the Gate; it is
     * here so the total cannot silently double.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 150 microseconds')]
    public function benchGateAllowsWithSynchronousAudit(): void
    {
        $this->writeThroughGate->allows($this->allowedIdentity, 'users.create');
    }

    /**
     * Empties the buffer between iterations, never inside one.
     */
    public function flushDecisions(): void
    {
        $this->decisionSink->flush();
    }
}

/**
 * Takes the entries the flush produces and keeps none of them: the subject
 * above measures the decision, and a growing array would measure the machine's
 * allocator.
 */
final class DiscardingAuditSink implements AuditSinkInterface
{
    public function write(AuditEntry $entry): void
    {
        unset($entry);
    }
}
