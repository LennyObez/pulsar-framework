# WebAuthn/Passkeys

Pulsar provides FIDO2-compliant passwordless authentication with passkey support
through the bundled **`pulsar/auth`** extension.

## Where this lives

WebAuthn has no package of its own. It ships inside `pulsar/auth`, together with
OAuth2/OIDC, social SSO and TOTP two-factor; `extensions/auth/pulsar.json` records
that in its `replaces` list. Earlier revisions of this page told you to run
`composer require pulsar/webauthn`, which was wrong in every release it appeared
in — no package by that name has ever been published, and the merge into
`pulsar/auth` happened before 1.0.0.

There is nothing to install. `pulsar/auth` is infrastructure rather than an
application product, so it is on disk in any application that has the framework
and it loads at boot with no configuration entry. You only need to name it if your
application takes exclusive control of what loads:

```php
// config/app.php — an EXCLUSIVE allowlist; when set, only these load
'extensions' => [
    'enabled' => ['pulsar/auth', 'pulsar/orm'],
],
```

## Quick start

### Configuration

The extension ships `extensions/auth/config/auth.php` with defaults for all three
subsystems. To change them, put a `config/auth.php` in your application: the host
file **replaces** the extension's file rather than merging into it, so copy the
shipped file and edit it rather than writing only the keys you want to change.

```php
// config/auth.php
return [
    'social' => [/* … */],
    'oauth2' => [/* … */],

    'webauthn' => [
        'rp_name' => 'My Application',
        'rp_id' => 'example.com',
        'origin' => 'https://example.com',
        'user_verification' => 'preferred',
        'attestation' => 'none',
        'allowed_formats' => ['none', 'packed'],
        'challenge_ttl_seconds' => 300,
        'timeout' => 60000,
    ],
];
```

`WEBAUTHN_RP_NAME`, `WEBAUTHN_RP_ID` and `WEBAUTHN_ORIGIN` set the first three from
the environment if you would rather not ship a config file at all.

### HTTP endpoints are yours to register

The extension registers **no** `/webauthn/*` routes, and this is deliberate — see
the comment in `AuthExtension::boot()`. Every ceremony endpoint needs state the
extension does not own: the challenge has to be held server-side against the
caller's session, and register / authenticate / list / rename / revoke additionally
need the acting user's identity plus an ownership check on the credential. A route
that answered 500 because those were missing would advertise an endpoint that does
not exist, which is worse than a 404.

The ceremonies themselves are reachable through `WebAuthnServerInterface`, which
the extension does bind. Wire your own routes against your own session and user
store. The paths below are the conventional shape, not something the framework
provides:

| Endpoint                               | Method | Description                     |
| -------------------------------------- | ------ | ------------------------------- |
| `/webauthn/register/options`           | POST   | Generate registration options   |
| `/webauthn/register/verify`            | POST   | Verify registration response    |
| `/webauthn/authenticate/options`       | POST   | Generate authentication options |
| `/webauthn/authenticate/verify`        | POST   | Verify authentication response  |
| `/webauthn/authenticators`             | GET    | List user's authenticators      |
| `/webauthn/authenticators/{id}/rename` | PUT    | Rename an authenticator         |
| `/webauthn/authenticators/{id}`        | DELETE | Revoke an authenticator         |

## Registration ceremony

The framework side of both ceremonies is `WebAuthnServerInterface`. It generates a
challenge and returns it to you; **you** decide where that challenge is held between the
two round trips, and the only correct answer is server-side against the caller's session.

`generateRegistrationOptions()` returns a `RegistrationOptions` carrying two things: the
raw `challenge` string, which stays on the server, and `toArray()`, which is the
`PublicKeyCredentialCreationOptions` document the browser needs. Sending the challenge to
the client is unavoidable — it is inside that document, and the authenticator signs over
it. Reading it back **out of the request** is the mistake: a client that supplies its own
challenge can replay an assertion it captured earlier, and the replay protection described
under [Challenge binding](#challenge-binding) is gone. Earlier revisions of this page
showed exactly that, with the client echoing `options.challenge` into the verify request;
`AuthExtension::boot()` carries a warning about it next to the routes it declines to
register.

### 1. Server: issue registration options

```php
use Pulsar\Extension\Auth\WebAuthn\Contract\CredentialRepositoryInterface;
use Pulsar\Extension\Auth\WebAuthn\Contract\WebAuthnServerInterface;
use Pulsar\Extension\Auth\WebAuthn\PublicKey\CredentialSource;
use Pulsar\Security\Session\SessionInterface;

final readonly class RegisterOptionsController
{
    public function __construct(
        private WebAuthnServerInterface $webAuthn,
        private CredentialRepositoryInterface $credentials,
        private SessionInterface $session,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        // The user is YOURS. The extension has no idea who is calling.
        $user = $this->currentUser($request);

        // Excluding what is already registered stops a second credential for the
        // same authenticator being silently created.
        $exclude = array_map(
            static fn(CredentialSource $credential): string => $credential->credentialId,
            $this->credentials->findByUserId($user->id),
        );

        $options = $this->webAuthn->generateRegistrationOptions($user->id, $user->name, $exclude);

        // The challenge never leaves the server except inside the options
        // document the authenticator signs over.
        $this->session->set('webauthn.register.challenge', $options->challenge);
        $this->session->set('webauthn.register.user_id', $user->id);

        return new JsonResponse($options->toArray());
    }
}
```

### 2. Browser: create the credential

```javascript
const options = await (await fetch('/webauthn/register/options', { method: 'POST' })).json();
const credential = await navigator.credentials.create({ publicKey: options });
```

### 3. Server: verify the registration

```php
public function verify(ServerRequestInterface $request): ResponseInterface
{
    $challenge = $this->session->getNullableString('webauthn.register.challenge');
    $userId = $this->session->getNullableString('webauthn.register.user_id');

    // Consume it: SessionInterface has no atomic pull, so read then remove.
    // Expiry and one-time use are enforced by the ceremony; binding the challenge
    // to THIS session is yours -- see "Challenge binding".
    $this->session->remove('webauthn.register.challenge');
    $this->session->remove('webauthn.register.user_id');

    if ($challenge === null || $userId !== $this->currentUser($request)->id) {
        return new JsonResponse(['error' => 'no registration in progress'], 400);
    }

    $result = $this->webAuthn->verifyRegistration(
        credentialJson: (string) $request->getBody(),
        expectedChallenge: $challenge,   // from the session, never from the body
    );

    // $result->credential, $result->attestationFormat, $result->isDiscoverable
    return new JsonResponse(['registered' => true]);
}
```

`verifyRegistration()` throws `WebAuthnException` on any failure — a challenge mismatch, an
origin mismatch against the configured `origin`, an RP-ID hash mismatch against `rp_id`, a
clear user-presence flag, `user_verification: required` with the UV flag clear, an
attestation format outside `allowed_formats`, or a bad signature. Let it propagate to your
error handler; do not translate it into a 200.

On success it persists the `CredentialSource` through `CredentialRepositoryInterface`
itself. It does **not** create an `AuthenticatorRecord` — the ceremony has no
`AuthenticatorRepositoryInterface` — so if you want the credential to appear on a "your
security keys" screen, register it there in this handler, from `$result->credential`.

## Authentication ceremony

Same shape, with one extra decision the API forces you to make explicitly.

### 1. Server: issue authentication options

```php
// Pass the user id for the username-first flow; pass null for the
// discoverable-credential (passkey) flow, where the authenticator chooses.
$options = $this->webAuthn->generateAuthenticationOptions($userId);

$this->session->set('webauthn.auth.challenge', $options->challenge);
$this->session->set('webauthn.auth.user_id', $userId);   // may be null

return new JsonResponse($options->toArray());
```

### 2. Browser: get the assertion

```javascript
const options = await (
  await fetch('/webauthn/authenticate/options', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({}), // empty for the passkey (discoverable) flow
  })
).json();

const assertion = await navigator.credentials.get({ publicKey: options });
```

### 3. Server: verify the assertion

```php
$challenge = $this->session->getNullableString('webauthn.auth.challenge');
$expectedUserId = $this->session->getNullableString('webauthn.auth.user_id');

$this->session->remove('webauthn.auth.challenge');
$this->session->remove('webauthn.auth.user_id');

if ($challenge === null) {
    return new JsonResponse(['error' => 'no authentication in progress'], 400);
}

$result = $this->webAuthn->verifyAuthentication(
    credentialJson: (string) $request->getBody(),
    expectedChallenge: $challenge,
    expectedUserId: $expectedUserId,   // no default -- state which mode you are in
);

// $result->userId is who the assertion proves. Resolve THAT identity from your
// own user store and hand it to the guard; SessionGuard::login() takes an
// IdentityInterface, not an id.
$this->guard->login($this->users->findById($result->userId));
```

`$expectedUserId` deliberately has no default value. Passing `null` means "accept whoever
the authenticator asserts through a discoverable credential", which is correct for a
passkey login and an account-confusion vector everywhere else. Passing an identifier means
"this assertion must belong to this user". The parameter has no default so that neither can
be selected by omission. In the `null` case, authenticate the session from
`$result->userId` — the identity the assertion establishes — rather than from anything the
request said about itself.

## Supported features

### Authenticator types

- **Platform authenticators**: Touch ID, Windows Hello, Face ID (built into the device)
- **Cross-platform authenticators**: Security keys via USB, NFC, or BLE

### Passkeys (resident credentials)

Discoverable credentials that don't require a username. The authenticator stores the user handle and can be used for passwordless login.

### Attestation formats

`AttestationVerifier` implements exactly two:

- **`none`**: no attestation statement, unconditionally trusted — the default, and the
  right choice unless you have a reason to identify authenticator models.
- **`packed`**: self-attestation and basic (CA) attestation.

Anything else — `fido-u2f`, `android-key`, `apple`, `tpm` — throws
`WebAuthnException::invalidAttestation('Unsupported format: ...')`, whatever
`allowed_formats` says: listing a format the verifier does not implement widens the config
and not the code. `allowed_formats` narrows the two that exist; it cannot add a third.

### Clone detection

The signature counter is validated on each authentication. If the counter does not increase (indicating a cloned authenticator), the ceremony is rejected and a security event is emitted.

## Authenticator management

`AuthenticatorRepositoryInterface` provides the operations; the screens and the routes are
yours, and so is the ownership check on every one of them — the repository is addressed by
credential id and will happily rename or revoke a credential belonging to someone else if
you hand it one.

| Method                                 | Purpose                                                    |
| -------------------------------------- | ---------------------------------------------------------- |
| `listByUserId(string $userId)`         | The user's `AuthenticatorRecord`s — type, name, timestamps |
| `rename(string $credentialId, string)` | Change the display name                                    |
| `revoke(string $credentialId)`         | Deactivate; writes a `SecurityEvent` to the audit chain    |
| `countActive(string $userId)`          | Guard against revoking the last factor                     |

## Relationship to the TOTP two-factor system

There is **no code path between them**. `TwoFactorManager` (`src/Auth/TwoFactor/`) knows
nothing about WebAuthn, `WebAuthnServerInterface` knows nothing about two-factor purposes,
and no shared enum or resolver lets a user present a passkey where a TOTP code is expected.
Earlier revisions of this page said WebAuthn "integrates with Pulsar's existing two-factor
authentication system as an alternative factor"; nothing in the tree does that.

Both are available to an application, which is a different statement. If you want a passkey
to satisfy a step-up requirement, your handler decides that: run the assertion ceremony,
and on success record whatever your application treats as a satisfied second factor. Note
that `TwoFactorManager::verifyCode()` fails closed when no `TwoFactorRateLimiterInterface`
is bound — a WebAuthn ceremony does not go through it, so it is not throttled by that
limiter either. Rate-limit your ceremony endpoints yourself.

## Security

### Challenge binding

What the framework does:

- Mints the challenge in both ceremonies through
  `Pulsar\Extension\Auth\WebAuthn\Ceremony\Challenge`: 32 bytes made of a format
  marker, the issuance instant, and 24 cryptographically random bytes (192 bits of
  entropy, against the 16 bytes the WebAuthn specification asks for).
- Verifies, at `verify()` time, that the challenge inside the client data matches the
  `$expectedChallenge` you passed, and that the origin and RP ID hash match the configured
  `origin` and `rp_id`.
- **Enforces `challenge_ttl_seconds` (default 300).** The instant is carried inside the
  challenge, so `verify()` can date it without a server-side challenge store. A response
  answering a challenge older than the window is refused with `WebAuthnException`, error
  code `expired_challenge`, and the failure is audited like any other.

  The instant is read only from _your_ copy — the `$expectedChallenge` argument, which the
  server minted and stored. The copy the client echoes is never parsed, only compared with
  `hash_equals`. A value not in this format has no issuance instant and is refused for the
  same reason: a challenge this relying party never issued cannot be shown to be fresh, and
  accepting it would be a way around the window.

  `challenge_ttl_seconds: 0` therefore admits only a ceremony answered inside the same
  clock second, and a negative value admits nothing at all. Both read as "no window", not
  as "no limit".

- **Enforces one-time use.** A challenge is claimed by the FIRST response that answers
  it, through `Pulsar\Extension\Auth\WebAuthn\Contract\ChallengeStoreInterface`. A
  second response carrying the same challenge is refused with error code
  `replayed_challenge`, and the failure is audited. The TTL bounds the replay window; this
  is what closes it.

  The claim happens before the signature and attestation checks, so a challenge is spent
  whether or not the response that answered it verified. Consuming only on success would
  leave a captured challenge open to unlimited attempts for the rest of its window, which
  is the property single use exists to remove.

  `replayed_challenge` is kept distinct from `expired_challenge` on purpose: an expiry is
  what a user who walked away from the prompt produces, whereas a second answer to a
  challenge already answered is somebody resubmitting a captured ceremony. An audit trail
  that renders them as one code cannot tell the two apart.

  > **The default store is process-local.** `InMemoryChallengeStore` is bound by default
  > and is complete and correct for a single worker process. A deployment running several
  > PHP workers — the default shape of PHP-FPM, RoadRunner and FrankenPHP — **must** bind
  > a shared implementation, or a replay routed to a different worker than the original
  > ceremony finds an empty store and is admitted:
  >
  > ```php
  > $container->bind(ChallengeStoreInterface::class, fn() => new RedisChallengeStore($redis));
  > ```
  >
  > The port requires the claim to be ATOMIC — `consume()` both tests and marks, in one
  > indivisible step. A `has()` followed by a `markSpent()` written by the caller loses to
  > two concurrent replays that interleave between the two calls, which is exactly the race
  > an attacker firing one captured assertion at several workers is trying to win. Redis
  > `SET key value NX PX ttl`, a unique index on a challenge column, and memcached `add`
  > each give it; a read-then-write does not.

What the framework does **not** do, and you must:

- **Binding to the session.** Holding the challenge server-side against the caller's
  session is what makes it a challenge rather than a value the client picked. The
  extension cannot do this for you: it never sees your session. Remove it from the session
  once you have read it, as the handlers above do — the store now refuses the replay
  regardless, but a challenge left lying in a session is still a value you are carrying
  for no reason.

Earlier revisions of this page listed one-time use as something you had to build, because
the framework kept no challenge store and nothing marked a challenge spent. It keeps one
now; the session binding above is what remains yours.

### Audit trail

Both ceremonies take an `AuditLoggerInterface` and write through it, so — when
`PULSAR_MASTER_KEY` is set, which is what binds the logger at all — these events land in
the HMAC-chained trail:

| Action                             | Event            | Outcome   |
| ---------------------------------- | ---------------- | --------- |
| `webauthn.registration.started`    | `Authentication` | `Success` |
| `webauthn.registration.verified`   | `Authentication` | `Success` |
| `webauthn.registration.failed`     | `Authentication` | `Failure` |
| `webauthn.authentication.started`  | `Authentication` | `Success` |
| `webauthn.authentication.verified` | `Authentication` | `Success` |
| `webauthn.authentication.failed`   | `Authentication` | `Failure` |
| `webauthn.clone_detected`          | `SecurityEvent`  | `Failure` |

`AuthenticatorRepositoryInterface` mutations are audited too, so the inventory of a user's
second factors has a history and not just a current state:

| Action                              | Event                 | Outcome   | Notes                                                          |
| ----------------------------------- | --------------------- | --------- | -------------------------------------------------------------- |
| `webauthn.authenticator.registered` | `Authentication`      | `Success` | Carries the credential id, display name, type and AAGUID       |
| `webauthn.authenticator.renamed`    | `ConfigurationChange` | `Success` | Carries both the previous and the new display name             |
| `webauthn.authenticator.revoked`    | `SecurityEvent`       | `Success` | Carries `remaining_active`: how many factors the user has left |
| `webauthn.authenticator.revoked`    | `SecurityEvent`       | `Failure` | Credential id not registered — a stale client, or a probe      |
| `webauthn.authenticator.renamed`    | `SecurityEvent`       | `Failure` | Credential id not registered — a stale client, or a probe      |

Revocation is a `SecurityEvent` rather than a configuration change because stripping a
second factor is the step that precedes an account takeover, and it is the line a detection
rule watches for. Reads are deliberately not audited: `listByUserId()` and `countActive()`
run on every settings render and would bury the mutations that matter.

This is a contract obligation, not an implementation detail of the bundled in-memory
adapter: a persistent `AuthenticatorRepositoryInterface` you write must write the same
entries, because nothing else in the stack sees the mutation.

### Audit logging is required, not optional

WebAuthn does **not** degrade to a no-op logger. If no `AuditLoggerInterface` is bound,
resolving `WebAuthnServerInterface`, either ceremony, or `AuthenticatorRepositoryInterface`
throws a `RuntimeException` naming what is missing, rather than authenticating a second
factor or removing one with no record. Other subsystems in the framework — mail,
notifications, workflow, data purge — do degrade; they lose a record, whereas this would
lose the record of who proved possession of a factor. `PULSAR_MASTER_KEY` is what binds the
logger, so an unkeyed deployment has no WebAuthn.

### What is not keyed

WebAuthn is a public-key protocol and this implementation derives no key material of its
own: nothing under `extensions/auth/src/WebAuthn/` touches `MasterKey`, `KeyProviderInterface`
or the keyring. Verification uses the credential's own stored public key, and the challenge
is raw CSPRNG output rather than a signed token. `SubKeyId::WebAuthnChallenge = 10` is
reserved in the registry and is not used by any code.

The practical consequence: unlike audit logging or session encryption, WebAuthn does not
become inert when `PULSAR_MASTER_KEY` is unset — but the ceremonies resolve
`AuditLoggerInterface` unguarded, and that binding _is_ key-conditional, so resolving
`WebAuthnServerInterface` without a master key fails at the container rather than degrading.
Set the key.

## Extending

### Custom credential storage — required for any real deployment

`AuthServiceProvider` binds `InMemoryCredentialRepository` and
`InMemoryAuthenticatorRepository` by default. Both hold their state in a PHP array, so
**every registered passkey is lost when the process ends**. Under PHP-FPM that is the next
request: a credential registered in one request cannot be found in the next, and
authentication fails with "credential not found" rather than with anything that points at
the cause.

They are the defaults because the framework has no opinion about your schema, not because
they are usable. Bind persistent implementations of both before you register a single
credential:

```php
// In your application's composition root. Both names below are yours to write --
// the framework ships no database-backed WebAuthn repository.
$container->bind(
    CredentialRepositoryInterface::class,
    DatabaseCredentialRepository::class,
);

$container->bind(
    AuthenticatorRepositoryInterface::class,
    DatabaseAuthenticatorRepository::class,
);
```

Bind both. `CredentialRepositoryInterface` holds the key material the ceremonies verify
against and the signature counter clone detection depends on; `AuthenticatorRepositoryInterface`
holds what a user sees on a "your security keys" screen. Persisting one and not the other
leaves a working login with no manageable record of it, or the reverse.

## Architecture

The extension follows the contract-first pattern:

- **Contracts** (`Contract/`): Port interfaces defining the public API (`#[Api]`)
- **Adapters** (`Adapter/`): Library adapter implementations (`#[Internal]`)
- **Ceremony** (`Ceremony/`): Registration and authentication ceremonies
- **Authenticator** (`Authenticator/`): Authenticator management
- **Attestation** (`Attestation/`): Attestation format policy
- **PublicKey** (`PublicKey/`): Credential source storage

## Related

- [OAuth2/OIDC](oauth2-oidc.md) - authorization server
- [Session Management](session-management.md) - session infrastructure
- [ADR-0032](adr/0032-homegrown-auth-with-conformance-vectors-gate.md) - why the implementation is
  homegrown rather than a wrapper, and what gates it for GA. It supersedes ADR-0025 and ADR-0030,
  which mandated `web-auth/webauthn-lib` and are kept only as a record of the retracted decision.
