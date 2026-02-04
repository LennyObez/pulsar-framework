<?php

declare(strict_types=1);

namespace Pulsar\Observability\Log\Sink;

use Pulsar\Api\Api;
use Pulsar\Observability\Log\LogSinkInterface;

#[Api(since: '1.0.0')]
interface DeferredSinkInterface extends LogSinkInterface
{
    public function addSink(LogSinkInterface $sink): void;
}
