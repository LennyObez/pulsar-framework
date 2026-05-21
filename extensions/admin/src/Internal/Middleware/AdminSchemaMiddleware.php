<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\PolicyContext;
use Pulsar\Auth\Authorization\PolicyInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Admin\Config\AdminSchemaConfig;
use Pulsar\Extension\Admin\Domain\AdminPermission;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;

use function in_array;

/**
 * Guards all /schema routes.
 *
 * - Checks AdminSchemaConfig::enabled
 * - Checks per-operation permission (admin.schema.*)
 * - For destructive ops: enforces step-up auth requirement
 */
#[Internal]
final readonly class AdminSchemaMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AdminSchemaConfig $config,
        private PolicyInterface $policy,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->config->enabled) {
            return Response::json(
                ['error' => 'Schema management is disabled'],
                ResponseStatus::Forbidden->value,
            );
        }

        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        if ($identity === null) {
            return Response::json(
                ['error' => 'Authentication required'],
                ResponseStatus::Unauthorized->value,
            );
        }

        // Check view permission for all schema routes
        $result = $this->policy->evaluate(
            $identity,
            new PolicyContext(permission: AdminPermission::SchemaView->value),
        );

        if ($result !== true) {
            return Response::json(
                ['error' => 'Schema viewing not permitted'],
                ResponseStatus::Forbidden->value,
            );
        }

        // Determine operation from route/method
        $operation = $this->detectOperation($request);

        if ($operation !== null) {
            $permission = $this->mapOperationToPermission($operation);

            if ($permission !== null) {
                $opResult = $this->policy->evaluate(
                    $identity,
                    new PolicyContext(permission: $permission->value),
                );

                if ($opResult !== true) {
                    return Response::json(
                        ['error' => "Permission denied for schema operation: $operation"],
                        ResponseStatus::Forbidden->value,
                    );
                }

                // Step-up auth check for destructive operations
                if (in_array($operation, $this->config->requireStepUpFor, true)) {
                    /** @var mixed $stepUpVerified */
                    $stepUpVerified = $request->getAttribute('step_up_verified');

                    if ($stepUpVerified !== true && $request->getHeaderLine('X-Step-Up-Token') === '') {
                        return Response::json(
                            ['error' => 'Step-up authentication required for this operation', 'step_up_required' => true],
                            ResponseStatus::Forbidden->value,
                        );
                    }
                }
            }
        }

        return $handler->handle($request);
    }

    private function detectOperation(ServerRequestInterface $request): ?string
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        // Preview routes are read-only
        if (str_contains($path, '/preview/')) {
            return null;
        }

        if ($method === 'GET') {
            return null;
        }

        if ($method === 'DELETE') {
            if (str_contains($path, '/columns/')) {
                return 'drop_column';
            }

            if (str_contains($path, '/indexes/')) {
                return 'drop_index';
            }

            return 'drop';
        }

        if ($method === 'POST') {
            if (str_contains($path, '/rename')) {
                return 'rename';
            }

            if (str_contains($path, '/columns')) {
                return 'alter';
            }

            if (str_contains($path, '/indexes')) {
                return 'alter';
            }

            return 'create';
        }

        return null;
    }

    private function mapOperationToPermission(string $operation): ?AdminPermission
    {
        return match ($operation) {
            'create' => AdminPermission::SchemaCreate,
            'alter', 'drop_column', 'drop_index' => AdminPermission::SchemaAlter,
            'drop' => AdminPermission::SchemaDrop,
            'rename' => AdminPermission::SchemaRename,
            default => null,
        };
    }
}
