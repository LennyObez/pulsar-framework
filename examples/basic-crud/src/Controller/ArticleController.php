<?php

declare(strict_types=1);

namespace App\Controller;

use Pulsar\Http\Message\Response;
use Pulsar\Http\Request;

/**
 * CRUD controller for articles.
 *
 * Demonstrates the Pulsar controller pattern:
 * - Each action receives a Request and route params array
 * - Returns an immutable Response value object
 * - Uses Response factory methods (json, html) for common formats
 * - Route parameters are passed in the $params array
 */
final class ArticleController
{
    /** @var list<array{id: int, title: string, body: string, author: string, published: bool}> */
    private static array $articles = [
        ['id' => 1, 'title' => 'Getting Started with Pulsar', 'body' => 'Pulsar is a PHP 8.5+ HMVC framework...', 'author' => 'Admin', 'published' => true],
        ['id' => 2, 'title' => 'Building REST APIs', 'body' => 'Learn how to build REST APIs with Pulsar...', 'author' => 'Admin', 'published' => true],
        ['id' => 3, 'title' => 'Draft Article', 'body' => 'Work in progress...', 'author' => 'Editor', 'published' => false],
    ];

    /**
     * List all articles.
     *
     * GET /articles
     *
     * @param array<string, string> $params Route parameters (unused)
     */
    public function index(Request $request, array $params): Response
    {
        $published = $request->query('published');

        $articles = self::$articles;
        if ($published !== null) {
            $filter = $published === '1' || $published === 'true';
            $articles = array_values(array_filter(
                $articles,
                static fn(array $a): bool => $a['published'] === $filter,
            ));
        }

        return Response::json([
            'data' => $articles,
            'total' => count($articles),
        ]);
    }

    /**
     * Show a single article.
     *
     * GET /articles/{id}
     *
     * @param array<string, string> $params Route parameters with 'id'
     */
    public function show(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $article = $this->findById($id);

        if ($article === null) {
            return Response::json(
                ['error' => 'Article not found'],
                404,
            );
        }

        return Response::json(['data' => $article]);
    }

    /**
     * Create a new article.
     *
     * POST /articles
     * Body: {"title": "...", "body": "...", "author": "..."}
     *
     * @param array<string, string> $params Route parameters (unused)
     */
    public function store(Request $request, array $params): Response
    {
        $data = $request->json();

        if (!is_array($data) || !isset($data['title'], $data['body'], $data['author'])) {
            return Response::json(
                ['error' => 'Missing required fields: title, body, author'],
                422,
            );
        }

        $article = [
            'id' => count(self::$articles) + 1,
            'title' => (string) $data['title'],
            'body' => (string) $data['body'],
            'author' => (string) $data['author'],
            'published' => (bool) ($data['published'] ?? false),
        ];

        self::$articles[] = $article;

        return Response::json(
            ['data' => $article, 'message' => 'Article created'],
            201,
        );
    }

    /**
     * Update an existing article.
     *
     * PUT /articles/{id}
     * Body: {"title": "...", "body": "...", ...}
     *
     * @param array<string, string> $params Route parameters with 'id'
     */
    public function update(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        foreach (self::$articles as &$article) {
            if ($article['id'] === $id) {
                $data = $request->json();

                if (is_array($data)) {
                    if (isset($data['title'])) {
                        $article['title'] = (string) $data['title'];
                    }
                    if (isset($data['body'])) {
                        $article['body'] = (string) $data['body'];
                    }
                    if (isset($data['author'])) {
                        $article['author'] = (string) $data['author'];
                    }
                    if (isset($data['published'])) {
                        $article['published'] = (bool) $data['published'];
                    }
                }

                return Response::json(['data' => $article, 'message' => 'Article updated']);
            }
        }
        unset($article);

        return Response::json(
            ['error' => 'Article not found'],
            404,
        );
    }

    /**
     * Delete an article.
     *
     * DELETE /articles/{id}
     *
     * @param array<string, string> $params Route parameters with 'id'
     */
    public function destroy(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        foreach (self::$articles as $index => $article) {
            if ($article['id'] === $id) {
                array_splice(self::$articles, $index, 1);

                return Response::json(
                    ['message' => 'Article deleted'],
                    200,
                );
            }
        }

        return Response::json(
            ['error' => 'Article not found'],
            404,
        );
    }

    /**
     * Find an article by ID.
     *
     * @return array{id: int, title: string, body: string, author: string, published: bool}|null
     */
    private function findById(int $id): ?array
    {
        foreach (self::$articles as $article) {
            if ($article['id'] === $id) {
                return $article;
            }
        }

        return null;
    }
}
