<?php

declare(strict_types=1);

/**
 * Default English validation messages.
 *
 * Keys match validation rule names. Parameters use ICU
 * MessageFormat placeholders.
 */

return [
    // Core rules
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
    'alpha_numeric' => 'The {field} field must only contain letters and numbers.',
    'ascii' => 'The {field} field must contain only ASCII characters.',
    'slug' => 'The {field} field must be a valid slug.',
    'contains' => 'The {field} field must contain "{needle}".',
    'not_contains' => 'The {field} field must not contain "{needle}".',
    'ip' => 'The {field} field must be a valid IP address.',
    'uuid' => 'The {field} field must be a valid UUID.',

    // String rules
    'min_length' => 'The {field} field must be at least {min} characters.',
    'max_length' => 'The {field} field must not exceed {max} characters.',
    'starts_with' => 'The {field} field must start with one of: {values}.',
    'ends_with' => 'The {field} field must end with one of: {values}.',
    'not_regex' => 'The {field} format is invalid.',
    'lowercase' => 'The {field} field must be lowercase.',
    'uppercase' => 'The {field} field must be uppercase.',

    // Numeric rules
    'digits' => 'The {field} field must be {digits} digits.',
    'digits_between' => 'The {field} field must be between {min} and {max} digits.',
    'decimal' => 'The {field} field must have {places} decimal places.',
    'multiple_of' => 'The {field} field must be a multiple of {value}.',
    'positive' => 'The {field} field must be a positive number.',
    'negative' => 'The {field} field must be a negative number.',
    'divisible' => 'The {field} field must be divisible by {divisor}.',

    // Comparison rules
    'same' => 'The {field} and {other} fields must match.',
    'different' => 'The {field} and {other} fields must be different.',
    'greater_than' => 'The {field} field must be greater than {other}.',
    'greater_than_or_equal' => 'The {field} field must be greater than or equal to {other}.',
    'less_than' => 'The {field} field must be less than {other}.',
    'less_than_or_equal' => 'The {field} field must be less than or equal to {other}.',
    'required_if' => 'The {field} field is required when {other} is {value}.',
    'required_unless' => 'The {field} field is required unless {other} is {value}.',
    'required_with' => 'The {field} field is required when {values} is present.',
    'required_without' => 'The {field} field is required when {values} is not present.',
    'prohibited' => 'The {field} field is prohibited.',
    'prohibited_if' => 'The {field} field is prohibited when {other} is {value}.',
    'prohibited_unless' => 'The {field} field is prohibited unless {other} is {value}.',

    // Date rules
    'date_time' => 'The {field} field must be a valid datetime.',
    'date_format' => 'The {field} field must match the format {format}.',
    'before_or_equal' => 'The {field} field must be a date before or equal to {date}.',
    'after_or_equal' => 'The {field} field must be a date after or equal to {date}.',
    'date_between' => 'The {field} field must be a date between {after} and {before}.',
    'timezone' => 'The {field} field must be a valid timezone.',

    // Array rules
    'array_min' => 'The {field} field must have at least {min} items.',
    'array_max' => 'The {field} field must not have more than {max} items.',
    'array_size' => 'The {field} field must contain exactly {size} items.',
    'distinct' => 'The {field} field has a duplicate value.',
    'each' => 'The {field} field contains an invalid element.',
    'key_exists' => 'The {field} field must contain the required keys.',

    // File rules
    'file' => 'The {field} field must be a file.',
    'file_size' => 'The {field} field must not be greater than {max} kilobytes.',
    'file_mimes' => 'The {field} field must be a file of type: {values}.',
    'image' => 'The {field} field must be an image.',
    'dimensions' => 'The {field} field has invalid image dimensions.',
    'max_file_size' => 'The {field} field must not be larger than {max} bytes.',
    'mimes' => 'The {field} field must be a file of type: {types}.',

    // Type rules
    'nullable' => 'The {field} field may be null.',
    'filled' => 'The {field} field must have a value when present.',
    'present' => 'The {field} field must be present.',
    'enum' => 'The {field} field must be a valid enum value.',
    'instance_of' => 'The {field} field must be an instance of {class}.',

    // Format rules
    'json' => 'The {field} field must be a valid JSON string.',
    'ipv4' => 'The {field} field must be a valid IPv4 address.',
    'ipv6' => 'The {field} field must be a valid IPv6 address.',
    'mac_address' => 'The {field} field must be a valid MAC address.',
    'credit_card' => 'The {field} field must be a valid credit card number.',
    'iban' => 'The {field} field must be a valid IBAN.',
    'bic' => 'The {field} field must be a valid BIC/SWIFT code.',
    'phone' => 'The {field} field must be a valid E.164 phone number.',
    'postal_code' => 'The {field} field must be a valid postal code.',
    'country_code' => 'The {field} field must be a valid ISO 3166-1 alpha-2 country code.',
    'currency_code' => 'The {field} field must be a valid ISO 4217 currency code.',
    'language_code' => 'The {field} field must be a valid ISO 639-1 language code.',

    // Database rules
    'exists' => 'The selected {field} is invalid.',

    // Regulated: Healthcare rules
    'mrn' => 'The {field} field must be a valid Medical Record Number.',
    'npi' => 'The {field} field must be a valid National Provider Identifier (NPI).',
    'hl7_date' => 'The {field} field must be a valid HL7 date.',
    'fhir_resource_id' => 'The {field} field must be a valid FHIR resource ID.',

    // Regulated: Financial rules
    'pan' => 'The {field} field must be a valid Primary Account Number (PAN).',
    'cvv' => 'The {field} field must be a valid CVV.',
    'expiration_date' => 'The {field} field must be a valid expiration date.',
    'routing_number' => 'The {field} field must be a valid ABA routing number.',
    'swift' => 'The {field} field must be a valid SWIFT/BIC code.',

    // Regulated: Identity rules
    'ssn' => 'The {field} field must be a valid Social Security Number format.',
    'ein' => 'The {field} field must be a valid Employer Identification Number (EIN).',
    'passport_number' => 'The {field} field must be a valid passport number.',
    'tax_id' => 'The {field} field must be a valid tax identification number.',

    // Regulated: Legal rules
    'case_number' => 'The {field} field must be a valid case number.',
    'bar_number' => 'The {field} field must be a valid bar number.',
    'jurisdiction_code' => 'The {field} field must be a valid jurisdiction code.',
];
