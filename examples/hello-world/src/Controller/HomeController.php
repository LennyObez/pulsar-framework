<?php

declare(strict_types=1);

namespace App\Controller;

use Pulsar\Core\Version;
use Pulsar\Http\Request;
use Pulsar\Http\Response;

/**
 * Example home controller for the hello-world application.
 */
final class HomeController
{
    /**
     * Display the welcome page.
     *
     * @param array<string, string> $params
     */
    public function index(Request $_request, array $_params): Response
    {
        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Welcome to Pulsar</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
                    background: linear-gradient(135deg, #1a1a2e 0%%, #16213e 100%%);
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    color: #fff;
                }
                .container {
                    text-align: center;
                    padding: 2rem;
                }
                h1 {
                    font-size: 3.5rem;
                    margin-bottom: 0.5rem;
                    background: linear-gradient(90deg, #e94560, #0f3460);
                    -webkit-background-clip: text;
                    -webkit-text-fill-color: transparent;
                    background-clip: text;
                }
                .version {
                    font-size: 1rem;
                    color: #888;
                    margin-bottom: 2rem;
                }
                .tagline {
                    font-size: 1.25rem;
                    color: #ccc;
                    margin-bottom: 2rem;
                }
                .info {
                    background: rgba(255, 255, 255, 0.05);
                    border-radius: 8px;
                    padding: 1.5rem;
                    margin-top: 2rem;
                }
                .info h2 {
                    font-size: 1rem;
                    color: #e94560;
                    margin-bottom: 1rem;
                }
                .info p {
                    font-size: 0.875rem;
                    color: #aaa;
                    line-height: 1.6;
                }
                code {
                    background: rgba(233, 69, 96, 0.2);
                    padding: 0.25rem 0.5rem;
                    border-radius: 4px;
                    font-family: 'Fira Code', monospace;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>Pulsar</h1>
                <p class="version">v%s</p>
                <p class="tagline">PHP HMVC Framework for Mission-Critical Applications</p>
                <div class="info">
                    <h2>Getting Started</h2>
                    <p>
                        You're looking at the hello-world example application.<br>
                        Try visiting <code>/api/status</code> or <code>/greet/World</code>
                    </p>
                </div>
            </div>
        </body>
        </html>
        HTML;

        return Response::html(sprintf($html, Version::full()));
    }

    /**
     * Greet a person by name.
     *
     * @param array<string, string> $params
     */
    public function greet(Request $_request, array $params): Response
    {
        $name = $params['name'] ?? 'Guest';
        $name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

        return Response::html(sprintf(
            '<!DOCTYPE html><html><head><title>Hello, %s!</title></head>' .
            '<body style="font-family: sans-serif; display: flex; align-items: center; ' .
            'justify-content: center; min-height: 100vh; margin: 0;">' .
            '<h1>Hello, %s!</h1></body></html>',
            $name,
            $name,
        ));
    }

    /**
     * Return API status as JSON.
     *
     * @param array<string, string> $params
     */
    public function status(Request $_request, array $_params): Response
    {
        return Response::json([
            'status' => 'ok',
            'framework' => 'Pulsar',
            'version' => Version::full(),
            'php_version' => PHP_VERSION,
            'timestamp' => date('c'),
        ]);
    }
}
