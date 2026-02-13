<?php

declare(strict_types=1);

/**
 * Pulsar UI Playground — Dev Server Router
 *
 * Usage: php -S localhost:8942 resources/playground/router.php
 *
 * Routes:
 *   GET  /                    — Main playground page
 *   GET  /catalog             — Component catalog (loaded in iframe)
 *   GET  /api/csrf-token      — Generate CSRF token
 *   GET  /api/themes          — List available themes
 *   GET  /api/themes/{name}   — Read theme CSS content
 *   POST /api/themes/{name}   — Save theme CSS content
 *   DELETE /api/themes/{name} — Delete custom theme
 *   Static files from playground/ and ui/
 */

// Dev-only server — never run in production.
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$playgroundDir = __DIR__;
$resourcesDir = dirname(__DIR__);
$themesDir = $resourcesDir . DIRECTORY_SEPARATOR . 'themes';
$uiDir = $resourcesDir . DIRECTORY_SEPARATOR . 'ui';

// Ensure themes directory exists
if (!is_dir($themesDir)) {
    mkdir($themesDir, 0755, true);
}

// CSRF token management
session_start();

/**
 * Generate or retrieve the current CSRF token.
 */
function getCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Validate the CSRF token from the request header.
 */
function validateCsrf(): bool
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    return hash_equals(getCsrfToken(), $token);
}

/**
 * Validate a theme name: alphanumeric and hyphens only.
 */
function isValidThemeName(string $name): bool
{
    return (bool) preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?$/', $name);
}

/**
 * Resolve a safe path within the themes directory (prevent path traversal).
 */
function resolveThemePath(string $themesDir, string $name): ?string
{
    $path = $themesDir . DIRECTORY_SEPARATOR . $name . '.css';
    $realThemesDir = realpath($themesDir);

    if ($realThemesDir === false) {
        return null;
    }

    // For new files, verify the parent directory resolves within themes dir
    $parentDir = realpath(dirname($path));

    if ($parentDir === false || !str_starts_with($parentDir, $realThemesDir)) {
        return null;
    }

    // For existing files, verify the file itself resolves within themes dir
    if (file_exists($path)) {
        $realPath = realpath($path);

        if ($realPath === false || !str_starts_with($realPath, $realThemesDir)) {
            return null;
        }
    }

    return $path;
}

/**
 * Send a JSON response.
 */
function jsonResponse(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Send an error JSON response.
 */
function jsonError(string $message, int $status = 400): never
{
    jsonResponse(['error' => $message], $status);
}

/**
 * Get the MIME type for a file extension.
 */
function getMimeType(string $extension): string
{
    return match (strtolower($extension)) {
        'html' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        default => 'application/octet-stream',
    };
}

// -------------------------------------------------------------------------
// API Routes
// -------------------------------------------------------------------------

// CSRF token endpoint
if ($requestUri === '/api/csrf-token' && $method === 'GET') {
    jsonResponse(['token' => getCsrfToken()]);
}

// List themes
if ($requestUri === '/api/themes' && $method === 'GET') {
    $themes = [];
    $files = glob($themesDir . DIRECTORY_SEPARATOR . '*.css');

    if ($files !== false) {
        foreach ($files as $file) {
            $themes[] = basename($file, '.css');
        }
    }

    sort($themes);
    jsonResponse($themes);
}

// Theme CRUD — /api/themes/{name}
if (preg_match('#^/api/themes/([^/]+)$#', $requestUri, $matches)) {
    $themeName = $matches[1];

    if (!isValidThemeName($themeName)) {
        jsonError('Invalid theme name. Use alphanumeric characters and hyphens only.', 400);
    }

    $themePath = resolveThemePath($themesDir, $themeName);

    if ($themePath === null) {
        jsonError('Invalid theme path.', 400);
    }

    // GET — read theme
    if ($method === 'GET') {
        if (!file_exists($themePath)) {
            jsonError('Theme not found.', 404);
        }

        $content = file_get_contents($themePath);
        jsonResponse(['name' => $themeName, 'css' => $content]);
    }

    // POST — save theme
    if ($method === 'POST') {
        if (!validateCsrf()) {
            jsonError('Invalid CSRF token.', 403);
        }

        if ($themeName === 'default') {
            jsonError('Cannot overwrite the default theme.', 403);
        }

        $body = file_get_contents('php://input');
        $data = json_decode($body, true);

        if (!is_array($data) || !isset($data['css']) || !is_string($data['css'])) {
            jsonError('Request body must contain a "css" string field.', 400);
        }

        $written = file_put_contents($themePath, $data['css']);

        if ($written === false) {
            jsonError('Failed to write theme file.', 500);
        }

        jsonResponse(['name' => $themeName, 'saved' => true]);
    }

    // DELETE — delete custom theme
    if ($method === 'DELETE') {
        if (!validateCsrf()) {
            jsonError('Invalid CSRF token.', 403);
        }

        if ($themeName === 'default') {
            jsonError('Cannot delete the default theme.', 403);
        }

        if (!file_exists($themePath)) {
            jsonError('Theme not found.', 404);
        }

        if (!unlink($themePath)) {
            jsonError('Failed to delete theme file.', 500);
        }

        jsonResponse(['name' => $themeName, 'deleted' => true]);
    }

    jsonError('Method not allowed.', 405);
}

// -------------------------------------------------------------------------
// Static File Serving
// -------------------------------------------------------------------------

// Route: GET / — serve index.html
if ($requestUri === '/' && $method === 'GET') {
    $file = $playgroundDir . DIRECTORY_SEPARATOR . 'index.html';

    if (file_exists($file)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($file);
        exit;
    }
}

// Route: GET /catalog — serve catalog.html
if ($requestUri === '/catalog' && $method === 'GET') {
    $file = $playgroundDir . DIRECTORY_SEPARATOR . 'catalog.html';

    if (file_exists($file)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($file);
        exit;
    }
}

// Serve playground static files (CSS, JS)
$playgroundFile = $playgroundDir . str_replace('/', DIRECTORY_SEPARATOR, $requestUri);

if (is_file($playgroundFile)) {
    $realPlayground = realpath($playgroundDir);
    $realFile = realpath($playgroundFile);

    if ($realPlayground !== false && $realFile !== false && str_starts_with($realFile, $realPlayground)) {
        $ext = pathinfo($realFile, PATHINFO_EXTENSION);
        header('Content-Type: ' . getMimeType($ext));
        readfile($realFile);
        exit;
    }
}

// Serve UI static files (CSS, JS, icons) — mapped under /ui/
if (str_starts_with($requestUri, '/ui/')) {
    $relativePath = substr($requestUri, 4); // Remove '/ui/' prefix
    $uiFile = $uiDir . str_replace('/', DIRECTORY_SEPARATOR, '/' . $relativePath);

    if (is_file($uiFile)) {
        $realUiDir = realpath($uiDir);
        $realFile = realpath($uiFile);

        if ($realUiDir !== false && $realFile !== false && str_starts_with($realFile, $realUiDir)) {
            $ext = pathinfo($realFile, PATHINFO_EXTENSION);
            header('Content-Type: ' . getMimeType($ext));
            readfile($realFile);
            exit;
        }
    }
}

// 404 for everything else
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo '404 Not Found';
