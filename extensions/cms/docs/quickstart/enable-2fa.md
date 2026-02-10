# Quickstart: enable two-factor authentication

**Estimated time: 3 minutes**

This quickstart walks you through enabling TOTP-based two-factor authentication on your CMS admin account.

## Prerequisites

- A CMS admin account
- An authenticator app installed on your device (Google Authenticator, Authy, 1Password, or any TOTP app)

## Step 1: navigate to 2FA enrollment

Log in to the CMS admin panel at `/admin/cms`.

Initiate 2FA enrollment by sending a request to the enrollment endpoint. If prompted for step-up authentication, re-enter your password.

```
POST /admin/cms/2fa/enroll
```

<!-- Screenshot: 2FA enrollment page with QR code -->

## Step 2: scan the QR code

1. Open your authenticator app.
2. Tap **Add Account** (or the **+** button).
3. Select **Scan QR Code**.
4. Point your camera at the QR code displayed on screen.

Your authenticator app now shows a new entry labeled **PulsarCMS** with a 6-digit code that refreshes every 30 seconds.

If you cannot scan the QR code, tap **Enter Manually** and type the Base32 secret shown below the QR code.

## Step 3: verify your setup

1. Read the current 6-digit code from your authenticator app.
2. Enter it in the **Verification Code** field.
3. Click **Confirm**.

```
POST /admin/cms/2fa/confirm
Content-Type: application/json

{
    "code": "123456",
    "secret": "YOUR_BASE32_SECRET"
}
```

If the code is valid, you see a success message: **"Two-factor authentication has been enabled."**

## Step 4: save your recovery codes

The enrollment response includes 8 recovery codes. These are single-use backup codes for when you cannot access your authenticator app.

**Write these codes down and store them in a safe place.**

Example:

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

Each code can only be used once. If you use some of them, generate new ones at `/admin/cms/2fa/recovery-codes`.

## Done

Your account is now protected with two-factor authentication. On your next login, you will be prompted for a TOTP code after entering your password.

## Troubleshooting

**Invalid code:** Ensure your device clock is accurate. TOTP codes depend on time synchronization.

**Lost authenticator:** Use one of your recovery codes to log in, then re-enroll with a new device.

## Next steps

- [2FA Setup Guide](../user/2fa-setup.md) - Managing 2FA, recovery codes, and disabling
- [Security Model](../security/security-model.md) - Understanding step-up authentication
