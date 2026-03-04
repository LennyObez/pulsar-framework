# Auth Extension Migration Notes

## Old Extensions to Remove

The following three extensions have been merged into `extensions/auth/` and should be deleted
after verifying all references have been updated:

1. **`extensions/social-sso/`** (44 files) - Social SSO (Google, GitHub, Facebook, Apple)
2. **`extensions/oauth2/`** (40 files) - OAuth2 server (authorization code, client credentials, refresh tokens)
3. **`extensions/webauthn/`** (24 files) - WebAuthn/FIDO2 passwordless authentication

## Namespace Mapping

| Old Namespace                 | New Namespace                     |
| ----------------------------- | --------------------------------- |
| `Pulsar\Extension\SocialSso\` | `Pulsar\Extension\Auth\Social\`   |
| `Pulsar\Extension\OAuth2\`    | `Pulsar\Extension\Auth\OAuth2\`   |
| `Pulsar\Extension\WebAuthn\`  | `Pulsar\Extension\Auth\WebAuthn\` |

## References That Need Updating After Deletion

### composer.json autoload

Remove these entries (already have `Pulsar\Extension\Auth\` added):

- `"Pulsar\\Extension\\SocialSso\\": "extensions/social-sso/src/"`
- `"Pulsar\\Extension\\OAuth2\\": "extensions/oauth2/src/"`
- `"Pulsar\\Extension\\WebAuthn\\": "extensions/webauthn/src/"`

### tools/php/phpstan.neon paths

Remove:

- `../../extensions/oauth2/src`
- `../../extensions/social-sso/src`
- `../../extensions/webauthn/src`

### tools/php/psalm.xml projectFiles

Remove:

- `<directory name="../../extensions/oauth2/src"/>`
- `<directory name="../../extensions/social-sso/src"/>`
- `<directory name="../../extensions/webauthn/src"/>`

### tools/php/phpunit.xml

Remove testsuites entries:

- `../../extensions/social-sso/tests/Unit`
- `../../extensions/oauth2/tests/Unit`
- `../../extensions/webauthn/tests/Unit`

Remove source/include entries:

- `../../extensions/social-sso/src`
- `../../extensions/oauth2/src`
- `../../extensions/webauthn/src`

### Tests in tests/Unit/Extension/

These tests reference old namespaces and need updating:

- `tests/Unit/Extension/SocialSso/` (all files)
- `tests/Unit/Extension/OAuth2/` (all files)
- `tests/Unit/Extension/WebAuthn/` (all files)

### tools/php/wiring-baseline.json

References to old extension file paths need updating.

## Config Key Change

Old: `config.social_sso`, `config.oauth2`, `config.webauthn` (three separate config entries)
New: `config.auth` (single entry with `social`, `oauth2`, `webauthn` sub-keys)
