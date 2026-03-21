<?php

declare(strict_types=1);

namespace Pulsar\Auth\Security;

use Pulsar\Api\Api;

/**
 * Classification of operations requiring elevated security checks.
 * @api
 */
#[Api(since: '1.0.0')]
enum SensitiveOperation: string
{
    case PasswordChange = 'password_change';
    case EmailChange = 'email_change';
    case MfaDisable = 'mfa_disable';
    case RecoveryCodeRegenerate = 'recovery_code_regenerate';
    case AccountDelete = 'account_delete';
    case ApiKeyCreate = 'api_key_create';
}
