<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Booking\Domain\Appointment;

/**
 * Delivers an appointment reminder over one channel.
 *
 * ReminderService took the two shipped senders as concrete final classes, so an
 * application could not put its own transactional-email or SMS provider in their
 * place — the substitution the extension model promises.
 * @api
 */
#[Api(since: '1.0.0')]
interface ReminderSenderInterface
{
    /**
     * Send the reminder for this appointment.
     *
     * Delivery failures are the sender's to absorb and log: a channel that is down must
     * not abort the scheduler run that is walking every due appointment.
     */
    public function send(Appointment $appointment): void;
}
