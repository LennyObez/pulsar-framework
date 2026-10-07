<?php

declare(strict_types=1);

namespace Pulsar\Runtime;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\Message\ServerRequest;

/**
 * Creates deterministic request fixtures for leak sentinel testing.
 *
 * All fixtures are self-contained: no network dependency, no external
 * state. They exercise common framework paths: routing, session,
 * cache, events, templates.
 */
#[Internal]
final class LeakFixtureFactory
{
    /** @return list<ServerRequestInterface> */
    public static function create(): array
    {
        return [
            new ServerRequest(
                method: 'GET',
                uri: '/',
            ),
            new ServerRequest(
                method: 'GET',
                uri: '/api/health',
            ),
            new ServerRequest(
                method: 'GET',
                uri: '/users',
                headers: ['Accept' => ['application/json']],
            ),
            new ServerRequest(
                method: 'GET',
                uri: '/users/1',
            ),
            new ServerRequest(
                method: 'POST',
                uri: '/users',
                headers: ['Content-Type' => ['application/json']],
                body: '{"name":"test"}',
            ),
            new ServerRequest(
                method: 'GET',
                uri: '/dashboard',
                headers: ['Cookie' => ['session=abc123']],
            ),
            new ServerRequest(
                method: 'GET',
                uri: '/search?q=test&page=1',
            ),
            new ServerRequest(
                method: 'GET',
                uri: '/static/style.css',
            ),
            new ServerRequest(
                method: 'GET',
                uri: '/api/v1/items',
                headers: ['Authorization' => ['Bearer token123']],
            ),
            new ServerRequest(
                method: 'POST',
                uri: '/login',
                headers: ['Content-Type' => ['application/x-www-form-urlencoded']],
                body: 'username=test&password=test',
            ),
        ];
    }
}
