<?php

declare(strict_types=1);

namespace Pulsar\Queue;

use Pulsar\Api\Api;

/**
 * Builds a worker carrying everything the composition root assembled for it.
 *
 * A caller whose options come from somewhere other than `config/queue.php` —
 * `queue:work` takes them from command-line flags — cannot reuse the bound
 * {@see Worker}, because that one was built with the configured options. Before
 * this contract existed, `queue:work` built its own from a driver and a logger
 * and lost the other eight collaborators, including the execution pipeline that
 * decrypts a payload before the handler sees it.
 *
 * @api
 */
#[Api(since: '1.0.0')]
interface WorkerFactoryInterface
{
    /**
     * A worker for these options, carrying every collaborator the host configured.
     */
    public function create(WorkerOptions $options): Worker;
}
