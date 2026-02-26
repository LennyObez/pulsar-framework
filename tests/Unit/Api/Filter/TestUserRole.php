<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Filter;

enum TestUserRole: string
{
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';
}
