<?php

declare(strict_types=1);

namespace Pulsar\AI\VectorStore;

use Pulsar\Api\Api;

/**
 * Distance metrics for vector similarity search.
 * @api
 */
#[Api(since: '1.0.0')]
enum DistanceMetric: string
{
    /** Cosine similarity (1 - cosine distance). Most common for text embeddings. */
    case Cosine = 'cosine';

    /** L2 (Euclidean) distance. Lower = more similar. */
    case L2 = 'l2';

    /** Inner product (dot product). Higher = more similar. */
    case InnerProduct = 'inner_product';
}
