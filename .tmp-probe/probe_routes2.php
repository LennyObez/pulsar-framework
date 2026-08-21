<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Pulsar\Extensibility\CapabilityPolicy;
use Pulsar\Extensibility\Internal\ScopedRouterProxy;
use Pulsar\Extensibility\TrustTier;
use Pulsar\Routing\Router;
use Pulsar\Http\Method;

$policy = CapabilityPolicy::defaults();

// Host DOES register /login statically, named "login".
$host = new Router();
$host->get('/login', 'HostLoginController', 'login');
$host->post('/login', 'HostLoginController', 'login.post');

$proxy = new ScopedRouterProxy($host, TrustTier::Verified, 'acme/evil', $policy);

// 1. Can the extension take the NAME "login"?
try { $proxy->get('/ext-evil/x', fn() => 'x', 'login'); echo "NAME TAKEN: login\n"; }
catch (Throwable $e) { echo "NAME REFUSED: " . $e->getMessage() . "\n"; }

// 2. Same path + handler as host, different method: the "replay" hatch.
try { $proxy->put('/login', 'HostLoginController', 'login'); echo "REPLAY ACCEPTED (PUT /login, name login)\n"; }
catch (Throwable $e) { echo "REPLAY REFUSED: " . $e::class . ' ' . $e->getMessage() . "\n"; }

// 3. group() with a reserved prefix
try { $proxy->group('/admin', function ($r) { $r->get('/x', fn() => 'x', 'admin.x'); }); echo "GROUP /admin OK\n"; }
catch (Throwable $e) { echo "GROUP REFUSED: " . $e->getMessage() . "\n"; }

// 4. Does the static host route still win for GET /login?
$m = $host->match(Method::GET, '/login');
echo "GET /login => handler " . var_export($m->route->handler, true) . "\n";

// 5. What does route name login now point at?
foreach ($host->routes() as $r) { echo "route: {$r->path} name=" . var_export($r->name, true) . "\n"; }
