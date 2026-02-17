<?php

declare(strict_types=1);

namespace Pulsar\Inertia;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Inertia page response.
 *
 * For Inertia requests (X-Inertia header), returns JSON with the
 * component name and props. For standard requests, wraps the page
 * data inside the root HTML template for initial load.
 *
 * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement: Psalm does not yet infer clone() return type
 */
#[Api(since: '1.0.0')]
final readonly class InertiaResponse
{
    /**
     * @param array<string, mixed> $props Page props
     * @param array<string, mixed> $sharedProps Shared props injected by middleware
     */
    private function __construct(
        private string $component,
        private array $props,
        private array $sharedProps,
        private string $url,
        private string $version,
    ) {}

    /**
     * Create a new Inertia page response.
     *
     * @param string $component The frontend component name (e.g. 'Users/Show')
     * @param array<string, mixed> $props Page-specific props
     */
    #[NoDiscard]
    public static function render(string $component, array $props = []): self
    {
        return new self(
            component: $component,
            props: $props,
            sharedProps: [],
            url: '',
            version: '',
        );
    }

    /**
     * Merge shared props (auth, flash, CSRF) into this response.
     *
     * @param array<string, mixed> $shared
     */
    #[NoDiscard]
    public function withSharedProps(array $shared): self
    {
        return clone($this, ['sharedProps' => array_merge($this->sharedProps, $shared)]);
    }

    /**
     * Set the current URL for the page.
     */
    #[NoDiscard]
    public function withUrl(string $url): self
    {
        return clone($this, ['url' => $url]);
    }

    /**
     * Set the asset version for cache busting.
     */
    #[NoDiscard]
    public function withVersion(string $version): self
    {
        return clone($this, ['version' => $version]);
    }

    /**
     * Build the page data object.
     *
     * @return array{component: string, props: array<string, mixed>, url: string, version: string}
     */
    public function toPageData(): array
    {
        return [
            'component' => $this->component,
            'props' => array_merge($this->sharedProps, $this->props),
            'url' => $this->url,
            'version' => $this->version,
        ];
    }

    /**
     * Build the JSON response for Inertia XHR requests.
     */
    #[NoDiscard]
    public function toJsonResponse(): Response
    {
        $json = json_encode(
            $this->toPageData(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return new Response(
            body: $json,
            status: ResponseStatus::OK,
            headers: new HeaderBag([
                'Content-Type' => 'application/json; charset=utf-8',
                'X-Inertia' => 'true',
                'Vary' => 'X-Inertia',
            ]),
        );
    }

    /**
     * Build the full HTML response for initial page loads.
     *
     * Embeds the page data as JSON in a data attribute for the client
     * to pick up and hydrate.
     */
    #[NoDiscard]
    public function toHtmlResponse(string $rootTemplate = ''): Response
    {
        $pageJson = htmlspecialchars(
            json_encode(
                $this->toPageData(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
            ENT_QUOTES,
            'UTF-8',
        );

        if ($rootTemplate !== '') {
            $html = str_replace('{{ $page }}', $pageJson, $rootTemplate);
        } else {
            $html = <<<HTML
                <!DOCTYPE html>
                <html lang="en">
                <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
                <body><div id="app" data-page="{$pageJson}"></div></body>
                </html>
                HTML;
        }

        return Response::html($html);
    }

    /**
     * Get the component name.
     */
    public function component(): string
    {
        return $this->component;
    }

    /**
     * Get the merged props.
     *
     * @return array<string, mixed>
     */
    public function props(): array
    {
        return array_merge($this->sharedProps, $this->props);
    }
}
