<?php

declare(strict_types=1);

namespace Pulsar\Api\TypeBridge;

use NoDiscard;
use Pulsar\Api\Api;

use function count;
use function implode;
use function sprintf;
use function str_replace;

/**
 * Generates a typed TypeScript API client from route definitions.
 *
 * Produces a complete .ts file with:
 * - Interface types for request/response shapes
 * - A typed client class with methods for each route
 * - Full IntelliSense support for IDE autocomplete
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TypeScriptClientGenerator
{
    /**
     * @param list<RouteDefinition> $routes
     * @param list<InterfaceDefinition> $interfaces
     */
    public function __construct(
        private array $routes,
        private array $interfaces = [],
        private string $baseUrl = '',
        private string $clientName = 'PulsarApiClient',
    ) {}

    /**
     * Generate the complete TypeScript client source code.
     */
    #[NoDiscard]
    public function generate(): string
    {
        $lines = [];
        $lines[] = '/**';
        $lines[] = ' * Auto-generated Pulsar API client.';
        $lines[] = ' * Do not edit: regenerate with: pulsar api:types';
        $lines[] = ' */';
        $lines[] = '';

        // Generate interfaces
        foreach ($this->interfaces as $interface) {
            $lines[] = $this->generateInterface($interface);
            $lines[] = '';
        }

        // Generate client class
        $lines[] = $this->generateClient();

        return implode("\n", $lines) . "\n";
    }

    private function generateInterface(InterfaceDefinition $def): string
    {
        $lines = [];
        $lines[] = sprintf('export interface %s {', $def->name);

        foreach ($def->properties as $prop) {
            $optional = $prop->optional ? '?' : '';
            $lines[] = sprintf('  %s%s: %s;', $prop->name, $optional, $prop->typeScriptType);
        }

        $lines[] = '}';

        return implode("\n", $lines);
    }

    private function generateClient(): string
    {
        $lines = [];
        $lines[] = 'interface RequestOptions {';
        $lines[] = '  headers?: Record<string, string>;';
        $lines[] = '  signal?: AbortSignal;';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = sprintf('export class %s {', $this->clientName);
        $lines[] = '  constructor(';
        $lines[] = sprintf('    private readonly baseUrl: string = \'%s\',', $this->baseUrl);
        $lines[] = '    private readonly defaultHeaders: Record<string, string> = {},';
        $lines[] = '  ) {}';
        $lines[] = '';

        // Generate methods grouped by namespace
        $grouped = $this->groupRoutesByNamespace();

        foreach ($grouped as $namespace => $routes) {
            if ($namespace !== '') {
                $lines[] = sprintf('  readonly %s = {', $namespace);
                foreach ($routes as $route) {
                    $lines[] = '    ' . $this->generateRouteMethod($route, true) . ',';
                }
                $lines[] = '  };';
                $lines[] = '';
            } else {
                foreach ($routes as $route) {
                    $lines[] = '  ' . $this->generateRouteMethod($route, false);
                    $lines[] = '';
                }
            }
        }

        $lines[] = '  private async request<T>(';
        $lines[] = '    method: string,';
        $lines[] = '    path: string,';
        $lines[] = '    body?: unknown,';
        $lines[] = '    options?: RequestOptions,';
        $lines[] = '  ): Promise<T> {';
        $lines[] = '    const response = await fetch(`${this.baseUrl}${path}`, {';
        $lines[] = '      method,';
        $lines[] = '      headers: {';
        $lines[] = '        \'Content-Type\': \'application/json\',';
        $lines[] = '        ...this.defaultHeaders,';
        $lines[] = '        ...options?.headers,';
        $lines[] = '      },';
        $lines[] = '      body: body ? JSON.stringify(body) : undefined,';
        $lines[] = '      signal: options?.signal,';
        $lines[] = '    });';
        $lines[] = '';
        $lines[] = '    if (!response.ok) {';
        $lines[] = '      throw new Error(`API error: ${response.status} ${response.statusText}`);';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    return response.json() as Promise<T>;';
        $lines[] = '  }';
        $lines[] = '}';

        return implode("\n", $lines);
    }

    private function generateRouteMethod(RouteDefinition $route, bool $arrow): string
    {
        $params = [];
        $pathExpr = "'" . $route->path . "'";

        foreach ($route->parameters as $param) {
            $paramType = $param->optional
                ? $param->typeScriptType . ' | undefined'
                : $param->typeScriptType;
            $params[] = $param->name . ': ' . $paramType;

            $pathExpr = str_replace(
                '{' . $param->name . '}',
                '${' . $param->name . '}',
                $pathExpr,
            );
        }

        if ($pathExpr !== "'" . $route->path . "'") {
            $pathExpr = '`' . substr($pathExpr, 1, -1) . '`';
        }

        if ($route->requestType !== null) {
            $params[] = 'data: ' . $route->requestType;
        }

        $params[] = 'options?: RequestOptions';
        $paramString = implode(', ', $params);
        $methodName = $this->shortMethodName($route->name);
        $body = $route->requestType !== null ? 'data' : 'undefined';

        if ($arrow) {
            return sprintf(
                '%s: async (%s): Promise<%s> => this.request<%s>(\'%s\', %s, %s, options)',
                $methodName,
                $paramString,
                $route->responseType,
                $route->responseType,
                strtoupper($route->method),
                $pathExpr,
                $body,
            );
        }

        $lines = [];
        $lines[] = sprintf(
            'async %s(%s): Promise<%s> {',
            $methodName,
            $paramString,
            $route->responseType,
        );
        $lines[] = sprintf(
            '    return this.request<%s>(\'%s\', %s, %s, options);',
            $route->responseType,
            strtoupper($route->method),
            $pathExpr,
            $body,
        );
        $lines[] = '  }';

        return implode("\n  ", $lines);
    }

    /**
     * @return array<string, list<RouteDefinition>>
     */
    private function groupRoutesByNamespace(): array
    {
        $grouped = [];

        foreach ($this->routes as $route) {
            $parts = explode('.', $route->name);

            if (count($parts) > 1) {
                $namespace = $parts[0];
            } else {
                $namespace = '';
            }

            $grouped[$namespace][] = $route;
        }

        return $grouped;
    }

    private function shortMethodName(string $routeName): string
    {
        $parts = explode('.', $routeName);

        if (count($parts) <= 1) {
            return $routeName;
        }

        array_shift($parts);

        return lcfirst(implode('', array_map('ucfirst', $parts)));
    }
}
