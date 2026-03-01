<?php

declare(strict_types=1);

namespace Pulsar\Api\OpenApi;

use Pulsar\Api\Api;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Request;

use function file_exists;
use function file_get_contents;
use function htmlspecialchars;

use const ENT_QUOTES;

/**
 * Serves the pre-built OpenAPI spec and a Swagger UI page.
 *
 * This controller does NOT generate the spec at runtime. It reads the
 * pre-built JSON artifact from disk and serves it alongside an embedded
 * Swagger UI HTML page.
 */
#[Api(since: '1.0.0')]
final readonly class SwaggerUiController
{
    public function __construct(
        private string $specPath,
        private string $specRoute = '/api/docs/openapi.json',
    ) {}

    /**
     * Serve the Swagger UI HTML page.
     */
    public function ui(Request $request): Response
    {
        $specUrl = htmlspecialchars($this->specRoute, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>API Documentation</title>
                <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
                <style>
                    html { box-sizing: border-box; overflow-y: scroll; }
                    *, *:before, *:after { box-sizing: inherit; }
                    body { margin: 0; background: #fafafa; }
                </style>
            </head>
            <body>
                <div id="swagger-ui"></div>
                <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
                <script>
                    SwaggerUIBundle({
                        url: "$specUrl",
                        dom_id: "#swagger-ui",
                        deepLinking: true,
                        presets: [
                            SwaggerUIBundle.presets.apis,
                            SwaggerUIBundle.SwaggerUIStandalonePreset
                        ],
                        layout: "BaseLayout"
                    });
                </script>
            </body>
            </html>
            HTML;

        return Response::html($html);
    }

    /**
     * Serve the pre-built OpenAPI JSON spec.
     */
    public function spec(Request $request): Response
    {
        if (!file_exists($this->specPath)) {
            return Response::json(
                ['error' => 'OpenAPI spec not found. Run the api:spec command to generate it.'],
                404,
            );
        }

        $content = file_get_contents($this->specPath);
        if ($content === false) {
            return Response::json(
                ['error' => 'Failed to read OpenAPI spec file.'],
                500,
            );
        }

        return (new Response(
            statusCode: 200,
            headers: [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'public, max-age=3600',
            ],
            body: $content,
        ));
    }
}
