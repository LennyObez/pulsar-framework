<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Pulsar\Api\Api;

/**
 * Available mail transport driver types.
 * @api
 */
#[Api(since: '1.0.0')]
enum MailDriverType: string
{
    case Smtp = 'smtp';
    case Ses = 'ses';
    case Mailgun = 'mailgun';
    case Postmark = 'postmark';
    case Sendgrid = 'sendgrid';
    case Log = 'log';
    case Array = 'array';
}
