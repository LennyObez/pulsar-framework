<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content\Event;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Dispatched after the CMS extension has fully booted.
 *
 * @psalm-api Public event class instantiated by CmsExtension::onBoot() and
 *            dispatched via the EventDispatcher.
 */
#[Api(since: '1.0.0')]
final readonly class CmsReady
{
    public function __construct(
        public DateTimeImmutable $timestamp,
    ) {}
}
