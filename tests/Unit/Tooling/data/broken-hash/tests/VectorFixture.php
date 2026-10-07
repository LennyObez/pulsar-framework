<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling\Data\BrokenHash\Tests;

function expectedAcceptKey(string $clientKey, string $guid): string
{
    return base64_encode(sha1($clientKey . $guid, true));
}
