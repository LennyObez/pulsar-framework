<?php

declare(strict_types=1);

namespace Pulsar\Codegen;

use Pulsar\Api\Api;

/**
 * Determines how to handle existing files during code generation.
 * @api
 */
#[Api(since: '1.0.0')]
enum OverwritePolicy: string
{
    /** Skip generation if the file already exists. */
    case Skip = 'skip';

    /** Overwrite the file regardless of whether it exists. */
    case Force = 'force';

    /** Fail the entire generation if any file already exists. */
    case Fail = 'fail';
}
