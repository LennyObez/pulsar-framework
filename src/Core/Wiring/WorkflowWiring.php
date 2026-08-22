<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Audit\NullAuditLogger;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Workflow\Engine\WorkflowEngineInterface;
use Pulsar\Workflow\Guard\GuardResolverInterface;
use Pulsar\Workflow\Internal\Engine\WorkflowEngine;
use Pulsar\Workflow\Internal\Guard\ContainerGuardResolver;
use Pulsar\Workflow\Internal\Storage\DatabaseTransitionLog;
use Pulsar\Workflow\Internal\Storage\DatabaseWorkflowStorage;
use Pulsar\Workflow\Internal\Timeout\PollingTimeoutHandler;
use Pulsar\Workflow\Storage\TransitionLogInterface;
use Pulsar\Workflow\Storage\WorkflowStorageInterface;
use Pulsar\Workflow\Timeout\TimeoutHandlerInterface;

/**
 * Activates the workflow engine when a database connection is available.
 *
 * The workflow subsystem (state machines with guarded transitions, durable
 * storage, transition audit log, timeout handling -- see docs/workflow-saga.md
 * and ADR-0027) is #[Api]. This class is its composition root: without this
 * wiring nothing binds WorkflowEngineInterface and it cannot be resolved.
 *
 * Storage is database-backed only (workflow state is durable by design), so
 * the whole subsystem stays dormant without a connection. An application that
 * binds its own WorkflowEngineInterface takes precedence; likewise each
 * storage port honours a pre-bound implementation. State payloads are
 * encrypted at rest when the security encryptor is bound; the transition log
 * doubles as the audit trail, wired to the shared audit logger.
 */
#[Internal]
final readonly class WorkflowWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        if ($container->has(WorkflowEngineInterface::class)) {
            return;
        }

        if (!$container->has(ConnectionManagerInterface::class) || !$container->has(EventDispatcherInterface::class)) {
            return;
        }

        /** @var ConnectionManagerInterface $connectionManager */
        $connectionManager = $container->get(ConnectionManagerInterface::class);
        $connection = $connectionManager->connection();

        $encryptor = $container->has(EncryptorInterface::class)
            ? $container->get(EncryptorInterface::class)
            : null;
        /** @var EncryptorInterface|null $encryptor */

        if ($container->has(WorkflowStorageInterface::class)) {
            /** @var WorkflowStorageInterface $storage */
            $storage = $container->get(WorkflowStorageInterface::class);
        } else {
            $storage = new DatabaseWorkflowStorage($connection, $encryptor);
            $container->instance(WorkflowStorageInterface::class, $storage);
        }

        if ($container->has(TransitionLogInterface::class)) {
            /** @var TransitionLogInterface $transitionLog */
            $transitionLog = $container->get(TransitionLogInterface::class);
        } else {
            $transitionLog = new DatabaseTransitionLog($connection);
            $container->instance(TransitionLogInterface::class, $transitionLog);
        }

        $guardResolver = new ContainerGuardResolver($container);
        $container->instance(GuardResolverInterface::class, $guardResolver);

        // ADR-0027: the polling handler is the default timeout strategy.
        $timeoutHandler = new PollingTimeoutHandler($storage);
        $container->instance(TimeoutHandlerInterface::class, $timeoutHandler);

        /** @var EventDispatcherInterface $eventDispatcher */
        $eventDispatcher = $container->get(EventDispatcherInterface::class);

        $auditLogger = $container->has(AuditLoggerInterface::class)
            ? $container->get(AuditLoggerInterface::class)
            : new NullAuditLogger();
        /** @var AuditLoggerInterface $auditLogger */

        $engine = new WorkflowEngine(
            $storage,
            $transitionLog,
            $guardResolver,
            $eventDispatcher,
            $auditLogger,
            timeoutHandler: $timeoutHandler,
        );
        $container->instance(WorkflowEngine::class, $engine);
        $container->instance(WorkflowEngineInterface::class, $engine);
    }
}
