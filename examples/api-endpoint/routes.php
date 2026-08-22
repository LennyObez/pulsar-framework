<?php

declare(strict_types=1);

use App\Controller\UserApiController;
use Pulsar\Routing\Router;

/**
 * API routes with versioned prefix.
 *
 * Demonstrates:
 * - API route grouping with a version prefix
 * - Resource-style route registration
 * - Named routes for URL generation
 * - Public vs authenticated endpoints
 */
return static function (Router $router): void {
    // Public health check (no auth required)
    $router->get('/api/v1/health', [UserApiController::class, 'health'], 'api.health');

    // Protected user management endpoints
    // In production, wrap these with AuthenticationMiddleware:
    //   $router->group('/api/v1/users', function (Router $r) { ... })
    //          ->middleware(AuthenticationMiddleware::class);
    $router->get('/api/v1/users', [UserApiController::class, 'index'], 'api.users.index');
    $router->get('/api/v1/users/{id}', [UserApiController::class, 'show'], 'api.users.show');
    $router->post('/api/v1/users', [UserApiController::class, 'store'], 'api.users.store');
};
