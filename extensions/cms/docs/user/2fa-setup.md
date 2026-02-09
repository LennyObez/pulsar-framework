# Two-Factor Authentication (2FA) Setup Guide

This guide explains how to enable two-factor authentication on your CMS account, enroll your authenticator app, manage recovery codes, and understand step-up authentication.

## Overview

Pulsar CMS supports TOTP-based two-factor authentication (2FA) using any compatible authenticator app (Google Authenticator, Authy, 1Password, Bitwarden, etc.). 2FA adds a second verification layer beyond your password, protecting against credential compromise.

## Prerequisites

- An authenticator app installed on your mobile device or computer
- A CMS account with the `cms.users.manage` permission (Admin role)
- Step-up authentication verified for the current session

## Step 1: Begin Enrollment

1. Navigate to **Admin > CMS > 2FA** or use the 2FA enrollment endpoint.
2. The system requires step-up authentication before proceeding. You may be prompted to re-enter your password.
3. Once verified, the enrollment process begins.

```
POST /admin/cms/2fa/enroll
```

The enrollment endpoint returns:

| Field              | Description                                |
| ------------------ | ------------------------------------------ |
| `secret`           | Base32-encoded TOTP secret                 |
| `provisioning_uri` | `otpauth://` URI for the authenticator app |
| `qr_code_svg`      | SVG image of the QR code for scanning      |
| `recovery_codes`   | 8 single-use recovery codes                |
| `digits`           | Number of digits in the TOTP code (6)      |
| `period`           | Time step in seconds (30)                  |

## Step 2: Scan the QR Code

<!-- Screenshot: QR code enrollment screen -->

1. Open your authenticator app.
2. Tap **Add Account** or the **+** button.
3. Choose **Scan QR Code**.
4. Point your camera at the QR code displayed on screen.
5. The app adds a new entry labeled **PulsarCMS** (or the configured issuer name).

### Manual Entry

If you cannot scan the QR code:

1. Choose **Enter Manually** in your authenticator app.
2. Enter the account name (your user ID or email).
3. Enter the Base32 secret displayed on screen.
4. Set the type to **Time-based** (TOTP).
5. Set digits to **6** and period to **30 seconds**.

## Step 3: Verify Your Setup

After adding the account to your authenticator app:

1. Wait for the app to generate a 6-digit code.
2. Enter the code in the **Verification Code** field.
3. Click **Confirm**.

```
POST /admin/cms/2fa/confirm
Content-Type: application/json

{
    "code": "123456",
    "secret": "BASE32ENCODEDSECRETHEREXX"
}
```

If the code is valid, 2FA is enabled on your account. If the code is invalid, you receive an error and can try again.

### Verification Failure

If verification fails:

- Wait for a new code to appear in your authenticator app (codes change every 30 seconds)
- Ensure your device's clock is synchronized (TOTP relies on accurate time)
- Verify you scanned the correct QR code
- Try manual entry if QR scanning did not work

## Step 4: Save Recovery Codes

Recovery codes are single-use backup codes for when you cannot access your authenticator app.

**Store these codes securely.** Each code can be used exactly once.

Example recovery codes:

```
A1B2-C3D4
E5F6-G7H8
J9K0-L1M2
N3P4-Q5R6
S7T8-U9V0
W1X2-Y3Z4
A5B6-C7D8
E9F0-G1H2
```

### Storage Recommendations

- Print them and store in a secure physical location
- Save in an encrypted password manager
- Do not store them in plain text on your computer
- Do not photograph them with a phone that syncs to cloud storage

## Using 2FA

### Regular Login

After 2FA is enabled, the login flow adds a second step:

1. Enter your username and password as usual.
2. You are prompted for your 2FA code.
3. Open your authenticator app and enter the current 6-digit code.
4. Access is granted.

### Step-Up Authentication

Certain sensitive operations require step-up authentication, which means re-verifying your identity even within an active session:

| Operation                    | Requires Step-Up |
| ---------------------------- | ---------------- |
| Enabling/disabling 2FA       | Yes              |
| Regenerating recovery codes  | Yes              |
| Resetting another user's 2FA | Yes              |
| Changing security settings   | Yes              |

Step-up authentication is valid for a configurable duration:

```php
'security' => [
    'step_up_ttl_minutes' => 15,
],
```

After the TTL expires, you must re-authenticate for the next sensitive operation.

### Using Recovery Codes

If you lose access to your authenticator app:

1. On the 2FA prompt, click **Use Recovery Code** (or enter a recovery code in place of the TOTP code).
2. Enter one of your unused recovery codes.
3. Access is granted and the used code is invalidated.

After using recovery codes, regenerate new ones immediately.

## Managing 2FA

### Regenerating Recovery Codes

If you have used some recovery codes or suspect they may be compromised:

1. Navigate to the 2FA management page.
2. Click **Regenerate Recovery Codes**.
3. Step-up authentication is required.
4. New codes are generated and all previous codes are invalidated.

```
POST /admin/cms/2fa/recovery-codes
```

### Disabling 2FA

To disable 2FA on your account:

1. Navigate to the 2FA management page.
2. Click **Disable 2FA**.
3. Step-up authentication is required.
4. You must provide a reason (minimum 10 characters) for disabling 2FA.

```
POST /admin/cms/2fa/disable
Content-Type: application/json

{
    "reason": "Switching to a new authenticator device"
}
```

The reason is recorded in the audit log for compliance purposes.

### Admin: Resetting Another User's 2FA

Administrators can reset 2FA for other users:

1. Navigate to **Admin > CMS > Users > {id}** (`/admin/cms/users/{id}`).
2. Click **Reset 2FA**.
3. Step-up authentication is required.
4. The user's 2FA is disabled, and they can re-enroll.

```
POST /admin/cms/users/{id}/reset-2fa
```

This action is audit-logged with the administrator's identity and the affected user's ID.

## Audit Events

All 2FA operations are recorded in the audit log:

| Event                                | When                                    |
| ------------------------------------ | --------------------------------------- |
| `cms.2fa.enrollment_started`         | User begins 2FA enrollment              |
| `cms.2fa.enrollment_confirmed`       | User successfully verifies initial code |
| `cms.2fa.enrollment_confirm_failed`  | User provides invalid verification code |
| `cms.2fa.verify_success`             | Successful TOTP verification            |
| `cms.2fa.verify_failed`              | Failed TOTP verification                |
| `cms.2fa.disabled`                   | 2FA is disabled (with reason)           |
| `cms.2fa.recovery_codes_regenerated` | New recovery codes generated            |

## TOTP Technical Details

| Parameter       | Value                  |
| --------------- | ---------------------- |
| Algorithm       | HMAC-SHA1 (RFC 6238)   |
| Digits          | 6                      |
| Period          | 30 seconds             |
| Secret length   | 160 bits               |
| Secret encoding | Base32 (RFC 4648)      |
| QR code format  | SVG                    |
| Recovery codes  | 8 codes per generation |

## Troubleshooting

### "Invalid verification code" on enrollment

- Ensure your device clock is accurate (within 30 seconds of actual time)
- Verify you are reading the code for the correct account in your authenticator app
- Wait for a fresh code if the current one is about to expire

### Lost access to authenticator and recovery codes

Contact your CMS administrator. They can reset your 2FA using the admin user management panel, after which you can re-enroll.

### "Step-up authentication required" error

Re-enter your password when prompted. Step-up sessions expire after the configured TTL (default: 15 minutes).

## Next Steps

- [Security Model](../security/security-model.md) - Complete security architecture
- [Audit Events Reference](../security/audit-events.md) - All audit-logged events
- [Settings Reference](settings-reference.md) - Security configuration options
