<?php

declare(strict_types=1);

return [
    // General
    'booking' => 'Booking',
    'appointment' => 'Appointment',
    'appointments' => 'Appointments',
    'service' => 'Service',
    'services' => 'Services',
    'category' => 'Category',
    'categories' => 'Categories',

    // Status labels
    'status.requested' => 'Requested',
    'status.confirmed' => 'Confirmed',
    'status.deposit_paid' => 'Deposit Paid',
    'status.reminded' => 'Reminded',
    'status.in_progress' => 'In Progress',
    'status.completed' => 'Completed',
    'status.cancelled' => 'Cancelled',
    'status.no_show' => 'No Show',
    'status.rescheduled' => 'Rescheduled',

    // Form labels
    'form.service' => 'Select Service',
    'form.date' => 'Preferred Date',
    'form.time' => 'Preferred Time',
    'form.name' => 'Full Name',
    'form.email' => 'Email Address',
    'form.phone' => 'Phone Number',
    'form.notes' => 'Additional Notes',
    'form.submit' => 'Book Appointment',

    // Actions
    'action.confirm' => 'Confirm',
    'action.cancel' => 'Cancel',
    'action.reschedule' => 'Reschedule',
    'action.complete' => 'Mark Completed',
    'action.no_show' => 'Mark No-Show',

    // Messages
    'message.booked' => 'Your appointment has been booked successfully.',
    'message.confirmed' => 'Appointment confirmed.',
    'message.cancelled' => 'Appointment cancelled.',
    'message.rescheduled' => 'Appointment rescheduled.',
    'message.completed' => 'Appointment marked as completed.',
    'message.no_show' => 'Appointment marked as no-show.',

    // Errors
    'error.not_found' => 'Appointment not found.',
    'error.too_soon' => 'Appointments must be booked at least :hours hours in advance.',
    'error.too_far' => 'Appointments cannot be booked more than :days days in advance.',
    'error.no_slots' => 'No available time slots for the selected date.',
    'error.cancellation_policy' => 'Appointments must be cancelled at least :hours hours before the scheduled time.',

    // Reminders
    'reminder.email.subject' => 'Reminder: Your appointment :number is coming up',
    'reminder.sms.body' => 'Reminder: Your appointment :number is on :date. Duration: :duration min.',

    // Admin
    'admin.dashboard' => 'Booking Dashboard',
    'admin.today' => "Today's Appointments",
    'admin.upcoming' => 'Upcoming Appointments',
    'admin.settings' => 'Booking Settings',
    'admin.services' => 'Manage Services',
    'admin.categories' => 'Service Categories',

    // Deposit
    'deposit.required' => 'A deposit of :amount is required to confirm your booking.',
    'deposit.paid' => 'Deposit paid',
    'deposit.pending' => 'Deposit pending',

    // Calendar
    'calendar.sync' => 'Sync to Google Calendar',
    'calendar.synced' => 'Synced to calendar',
];
