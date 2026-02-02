<?php

declare(strict_types=1);

use App\Controller\HomeController;
use Pulsar\Routing\Router;

/**
 * Register application routes.
 */
return static function (Router $router): void {
    // Home page
    $router->get('/', [HomeController::class, 'index'], 'home');

    // Greeting with parameter
    $router->get('/greet/{name}', [HomeController::class, 'greet'], 'greet');

    // API status endpoint
    $router->get('/api/status', [HomeController::class, 'status'], 'api.status');
};
