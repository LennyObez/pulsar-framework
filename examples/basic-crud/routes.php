<?php

declare(strict_types=1);

use App\Controller\ArticleController;
use Pulsar\Routing\Router;

/**
 * CRUD routes for the articles example.
 *
 * Demonstrates Pulsar routing conventions:
 * - $router->get()    for read operations
 * - $router->post()   for creation
 * - $router->put()    for updates
 * - $router->delete() for deletion
 * - Route parameters use {name} syntax
 * - Named routes (3rd argument) for URL generation
 */
return static function (Router $router): void {
    // List all articles (supports ?published=true filter)
    $router->get('/articles', [ArticleController::class, 'index'], 'articles.index');

    // Show a single article
    $router->get('/articles/{id}', [ArticleController::class, 'show'], 'articles.show');

    // Create a new article
    $router->post('/articles', [ArticleController::class, 'store'], 'articles.store');

    // Update an existing article
    $router->put('/articles/{id}', [ArticleController::class, 'update'], 'articles.update');

    // Delete an article
    $router->delete('/articles/{id}', [ArticleController::class, 'destroy'], 'articles.destroy');
};
