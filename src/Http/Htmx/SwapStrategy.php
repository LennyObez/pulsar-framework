<?php

declare(strict_types=1);

namespace Pulsar\Http\Htmx;

use Pulsar\Api\Api;

/**
 * Content swap strategies for hypermedia responses.
 *
 * Maps to px-swap attribute values on HTML elements.
 */
#[Api(since: '1.0.0')]
enum SwapStrategy: string
{
    case InnerHTML = 'innerHTML';
    case OuterHTML = 'outerHTML';
    case BeforeBegin = 'beforebegin';
    case AfterBegin = 'afterbegin';
    case BeforeEnd = 'beforeend';
    case AfterEnd = 'afterend';
    case Delete = 'delete';
    case None = 'none';
}
