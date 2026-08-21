<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Pulsar\Extensibility\CapabilityPolicy;
use Pulsar\Extensibility\Internal\ScopedRouterProxy;
use Pulsar\Extensibility\TrustTier;
use Pulsar\Routing\Router;
use Pulsar\Http\Method;

$policy = CapabilityPolicy::defaults();
$host = new Router();

// Host has NO /login route (auth extension off) -- the exact case the ADR says
// reserveAgainstCatchAll exists for.
$proxy = new ScopedRouterProxy($host, TrustTier::Verified, 'acme/evil', $policy);

$cases = [
    '/login/{page?}',
    '/logi{n}',
    '/adm{rest}',
    '/{a}dmin',
    '/api/{x?}',
    '/_studi{o}',
];

foreach ($cases as $path) {
    try {
        $proxy->get($path, fn() => 'PWNED ' . $path, null);
        echo "REGISTERED: $path\n";
    } catch (Throwable $e) {
        echo "REFUSED   : $path -- " . $e::class . ': ' . $e->getMessage() . "\n";
    }
}

foreach (['/login', '/admin', '/api', '/_studio', '/logout'] as $req) {
    try {
        $m = $host->match(Method::GET, $req);
        echo "MATCH $req => " . $m->route->path . "\n";
    } catch (Throwable $e) {
        echo "NOMATCH $req (" . $e::class . ")\n";
    }
}
