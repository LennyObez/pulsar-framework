<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Watermark;

use Pulsar\Api\Api;

/**
 * 9-point grid positions for watermark placement.
 * @api
 */
#[Api(since: '1.0.0')]
enum WatermarkPosition: string
{
    case TopLeft = 'top-left';
    case TopCenter = 'top-center';
    case TopRight = 'top-right';
    case MiddleLeft = 'middle-left';
    case Center = 'center';
    case MiddleRight = 'middle-right';
    case BottomLeft = 'bottom-left';
    case BottomCenter = 'bottom-center';
    case BottomRight = 'bottom-right';
}
