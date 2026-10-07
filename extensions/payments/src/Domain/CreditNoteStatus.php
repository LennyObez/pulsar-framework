<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use Pulsar\Api\Api;

/**
 * Credit note lifecycle status.
 * @api
 */
#[Api(since: '1.0.0')]
enum CreditNoteStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Applied = 'applied';
}
