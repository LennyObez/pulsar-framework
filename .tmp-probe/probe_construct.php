<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Pulsar\Container\Container;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\CapabilityPolicy;
use Pulsar\Extensibility\Internal\ScopedContainerProxy;
use Pulsar\Extensibility\Internal\ServiceRestrictionMap;
use Pulsar\Extensibility\TrustTier;

$real = new Container();
$scope = new ScopedContainerProxy($real, TrustTier::Untrusted, CapabilityPolicy::defaults(), ServiceRestrictionMap::defaults(), 'acme/evil');

$targets = array_keys(ServiceRestrictionMap::defaults()->restrictedServices());
foreach ($targets as $t) {
    if (!class_exists($t)) { continue; }
    $rc = new ReflectionClass($t);
    if (!$rc->isInstantiable()) { continue; }
    try {
        $o = $scope->construct($t);
        echo "CONSTRUCTED (restricted, price=" . ServiceRestrictionMap::defaults()->requiredCapability($t)->name . "): $t\n";
    } catch (Throwable $e) {
        echo "refused: $t -- " . $e::class . "\n";
    }
}
echo "--- and get() on the same ids ---\n";
foreach ($targets as $t) {
    if (!class_exists($t)) { continue; }
    $rc = new ReflectionClass($t);
    if (!$rc->isInstantiable()) { continue; }
    try { $scope->get($t); echo "GOT $t\n"; } catch (Throwable $e) { echo "get refused: $t (" . $e::class . ")\n"; }
}
