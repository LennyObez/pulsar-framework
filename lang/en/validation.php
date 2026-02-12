<?php

declare(strict_types=1);

/**
 * Default English validation messages.
 *
 * Keys match validation rule names. Parameters use ICU
 * MessageFormat placeholders.
 */

return [
    'required' => 'The {field} field is required.',
    'email' => 'The {field} field must be a valid email address.',
    'min' => 'The {field} field must be at least {min}.',
    'max' => 'The {field} field must not exceed {max}.',
    'between' => 'The {field} field must be between {min} and {max}.',
    'numeric' => 'The {field} field must be a number.',
    'string' => 'The {field} field must be a string.',
    'url' => 'The {field} field must be a valid URL.',
    'unique' => 'The {field} has already been taken.',
    'confirmed' => 'The {field} confirmation does not match.',
    'in' => 'The selected {field} is invalid.',
    'not_in' => 'The selected {field} is invalid.',
    'date' => 'The {field} field must be a valid date.',
    'before' => 'The {field} field must be a date before {date}.',
    'after' => 'The {field} field must be a date after {date}.',
    'size' => 'The {field} field must be exactly {size}.',
    'regex' => 'The {field} format is invalid.',
    'boolean' => 'The {field} field must be true or false.',
    'integer' => 'The {field} field must be an integer.',
    'array' => 'The {field} field must be an array.',
    'accepted' => 'The {field} field must be accepted.',
    'alpha' => 'The {field} field must only contain letters.',
    'alpha_num' => 'The {field} field must only contain letters and numbers.',
    'ip' => 'The {field} field must be a valid IP address.',
    'uuid' => 'The {field} field must be a valid UUID.',
];
