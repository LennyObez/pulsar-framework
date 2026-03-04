<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\FullSiteEditor;

use Pulsar\Api\Api;

/**
 * Predefined areas where template parts can be placed.
 */
#[Api(since: '1.0.0')]
enum TemplatePartArea: string
{
    case Header = 'header';
    case Footer = 'footer';
    case Sidebar = 'sidebar';
    case Navigation = 'navigation';
    case ContentArea = 'content_area';
    case Custom = 'custom';
}
