<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Pulsar\Container\Container;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\CapabilityPolicy;
use Pulsar\Extensibility\Internal\ScopedContainerProxy;
use Pulsar\Extensibility\Internal\ServiceRestrictionMap;
use Pulsar\Extensibility\TrustTier;

final class SecretHolder { public function __construct(public string $s = 'secret') {} }
final class NeedsContainer { public function __construct(public ContainerInterface $c) {} }

$real = new Container();
$real->instance(ContainerInterface::class, $real);
$real->instance('Pulsar\Security\Crypto\MasterKey', new stdClass());
$real->singleton('app.secret', fn() => new SecretHolder());
$real->instance('Pulsar\Config\AppConfig', new stdClass());

$scope = new ScopedContainerProxy(
    $real,
    TrustTier::Untrusted,
    CapabilityPolicy::defaults(),
    ServiceRestrictionMap::defaults(),
    'acme/evil',
);

echo "== 1. getBindings() at Untrusted ==\n";
try { $b = $scope->getBindings(); echo "  OK, " . count($b) . " ids: " . implode(', ', $b) . "\n"; }
catch (Throwable $e) { echo "  refused: " . $e->getMessage() . "\n"; }
try { $i = $scope->getInstances(); echo "  instances: " . implode(', ', $i) . "\n"; }
catch (Throwable $e) { echo "  refused: " . $e->getMessage() . "\n"; }

echo "== 2. has()/get() on MasterKey ==\n";
var_dump($scope->has('Pulsar\Security\Crypto\MasterKey'));

echo "== 3. construct(NeedsContainer) -- ADR says the scope is injected ==\n";
try { $o = $scope->construct(NeedsContainer::class); echo "  built, got " . $o->c::class . "\n"; }
catch (Throwable $e) { echo "  " . $e::class . ": " . $e->getMessage() . "\n"; }

echo "== 4. call(fn(ContainerInterface \$c)) ==\n";
try { $r = $scope->call(fn(ContainerInterface $c) => $c::class); echo "  got $r\n"; }
catch (Throwable $e) { echo "  " . $e::class . ": " . $e->getMessage() . "\n"; }

echo "== 5. public construct() of an arbitrary framework class, uncontained ==\n";
try { $o = $scope->construct(\Pulsar\Routing\Router::class); echo "  built " . $o::class . " (a real Router, uncontained)\n"; }
catch (Throwable $e) { echo "  " . $e::class . ": " . $e->getMessage() . "\n"; }
try { $o = $scope->construct(\Pulsar\Container\Container::class); echo "  built " . $o::class . " (a real Container, uncontained)\n"; }
catch (Throwable $e) { echo "  " . $e::class . ": " . $e->getMessage() . "\n"; }
