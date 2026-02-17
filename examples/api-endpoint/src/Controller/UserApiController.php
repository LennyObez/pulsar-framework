<?php

declare(strict_types=1);

namespace App\Controller;

use Pulsar\Http\Message\Response;
use Pulsar\Http\Request;

/**
 * REST API controller with validation and authentication.
 *
 * Demonstrates:
 * - JSON request/response handling
 * - Input validation via FormRequest
 * - Proper HTTP status codes
 * - Content negotiation
 * - Error response formatting
 */
final class UserApiController
{
    /** @var list<array{id: int, name: string, email: string, role: string, bio: string|null}> */
    private static array $users = [
        ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com', 'role' => 'admin', 'bio' => null],
        ['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com', 'role' => 'editor', 'bio' => 'Tech writer'],
    ];

    /**
     * List all users with optional role filtering.
     *
     * GET /api/v1/users
     * Query: ?role=admin
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $users = self::$users;

        // Filter by role if requested
        $role = $request->query('role');
        if (is_string($role) && $role !== '') {
            $users = array_values(array_filter(
                $users,
                static fn(array $u): bool => $u['role'] === $role,
            ));
        }

        return Response::json([
            'data' => $users,
            'meta' => [
                'total' => count($users),
                'page' => 1,
                'per_page' => 25,
            ],
        ]);
    }

    /**
     * Show a single user.
     *
     * GET /api/v1/users/{id}
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        foreach (self::$users as $user) {
            if ($user['id'] === $id) {
                return Response::json(['data' => $user]);
            }
        }

        return Response::json(
            ['error' => 'User not found', 'code' => 'USER_NOT_FOUND'],
            404,
        );
    }

    /**
     * Create a new user.
     *
     * POST /api/v1/users
     * Body: {"name": "...", "email": "...", "role": "...", "bio": "..."}
     *
     * In a real app, you would type-hint CreateUserRequest as the first
     * parameter and the framework validates automatically:
     *
     *   public function store(CreateUserRequest $request, array $params): Response
     *
     * @param array<string, string> $params
     */
    public function store(Request $request, array $params): Response
    {
        $data = $request->json();

        if (!is_array($data)) {
            return Response::json(
                ['error' => 'Invalid JSON body'],
                400,
            );
        }

        // Manual validation (FormRequest does this automatically)
        $errors = [];
        if (!isset($data['name']) || !is_string($data['name']) || strlen($data['name']) < 2) {
            $errors['name'] = 'Name is required and must be at least 2 characters.';
        }
        if (!isset($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email address is required.';
        }
        if (!isset($data['role']) || !in_array($data['role'], ['admin', 'editor', 'viewer'], true)) {
            $errors['role'] = 'Role must be one of: admin, editor, viewer.';
        }

        if ($errors !== []) {
            return Response::json(
                ['error' => 'Validation failed', 'errors' => $errors],
                422,
            );
        }

        $user = [
            'id' => count(self::$users) + 1,
            'name' => (string) $data['name'],
            'email' => (string) $data['email'],
            'role' => (string) $data['role'],
            'bio' => isset($data['bio']) ? (string) $data['bio'] : null,
        ];

        self::$users[] = $user;

        return Response::json(
            ['data' => $user, 'message' => 'User created'],
            201,
        );
    }

    /**
     * Health check endpoint (no auth required).
     *
     * GET /api/v1/health
     *
     * @param array<string, string> $params
     */
    public function health(Request $request, array $params): Response
    {
        return Response::json([
            'status' => 'healthy',
            'version' => '1.0.0',
            'timestamp' => date('c'),
        ]);
    }
}
