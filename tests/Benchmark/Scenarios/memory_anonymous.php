<?php

declare(strict_types=1);

/**
 * Memory profiling scenario: anonymous JSON API request.
 *
 * Runs in a fresh PHP process. Outputs peak memory in bytes to stdout.
 */

require __DIR__ . '/../../../vendor/autoload.php';

use Pulsar\Http\Message\ServerRequest;
use Pulsar\Tests\Benchmark\Support\BenchmarkPipelineFactory;

$factory = new BenchmarkPipelineFactory();
$pipeline = $factory->createPipeline('request.anonymous_json_api');
$handler = $factory->createHandler();

$request = new ServerRequest(method: 'GET', uri: '/bench/api/resource');
$request = $request->withHeader('Accept', 'application/json');

$response = $pipeline->process($request, $handler);

echo memory_get_peak_usage(true);
