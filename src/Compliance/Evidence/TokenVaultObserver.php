<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Evidence;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ExecutedSubject;
use Pulsar\Compliance\Control\Measurement;
use Pulsar\Compliance\Control\Observation;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Security\Crypto\TokenizationServiceInterface;
use Pulsar\Security\Crypto\TokenStoreInterface;
use Random\Randomizer;
use Throwable;

use function bin2hex;
use function count;
use function hash_equals;
use function implode;
use function sprintf;
use function str_contains;
use function strlen;

/**
 * Whether the token vault actually renders a value unreadable where it rests.
 *
 * WHY THIS EXISTS. ADR-0041 found PCI Req 3.4 reported Implemented because
 * `DatabaseTokenStore` existed in the source tree, and concluded the control
 * "should be closed by checking which store resolved". Doing exactly that closed
 * one gap and left the next one open, one layer down: on the repository this
 * class was written in, `TokenStoreInterface` resolves to `DatabaseTokenStore`
 * — the accepted, durable implementation — against a database that holds no
 * `token_vault` table at all. Every tokenize() would throw. The binding is
 * clean, the identity observation is present, and no value would ever be
 * rendered unreadable, because nothing can be stored.
 *
 * "The class is bound" is not weaker than "the class exists" by a little. It is
 * the same claim with a longer sentence. The only thing that separates a vault
 * that works from one that merely resolves is using it.
 *
 * WHAT IT RUNS, in order, all four against the live vault:
 *
 *   1. tokenize a synthetic value — the token must not be, or contain, the input
 *   2. read the stored form back — the persisted bytes must not contain the input
 *   3. detokenize — the original must come back, byte for byte
 *   4. remove — and the mapping must then be gone
 *
 * Together they are the requirement's own words: the value is *rendered
 * unreadable* where it is stored (2), by *index tokens* (1) backed by *strong
 * cryptography* that its holder can still reverse (3).
 *
 * THIS MEASUREMENT WRITES, and it is the only one in the evidence set that does.
 * That is deliberate and it is bounded:
 *
 *  - the value is 32 random hex characters from the framework's CSPRNG, never a
 *    PAN and never anything derived from the deployment's data;
 *  - the context is {@see PROBE_CONTEXT}, not `pan`, so the row cannot be
 *    mistaken for cardholder data by anyone reading the table, and it takes the
 *    generic tokenization path rather than the format-preserving one;
 *  - removal runs in a `finally`, so a failure in any later step still clears
 *    the row;
 *  - if removal itself fails, the report says so as a failed subject rather than
 *    staying quiet about a row it left behind.
 *
 * A deployment that cannot afford one insert and one delete against its own
 * token vault cannot afford the vault, and a compliance report that refuses to
 * touch the subject it reports on is the artefact ADR-0041 was written about.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class TokenVaultObserver
{
    /**
     * The tokenization context this check writes under.
     *
     * Deliberately not `pan`: that context triggers format-preserving
     * tokenization, which would put a PAN-shaped string into the vault, and a
     * synthetic PAN in a production vault is a worse artefact than no evidence.
     */
    public const string PROBE_CONTEXT = 'compliance.vault_probe';

    /** What the measurement names as having been exercised, in the report. */
    private const string SUBJECT = 'the live token vault';

    /** Bytes of randomness in the synthetic value; 16 bytes = 32 hex characters. */
    private const int PROBE_VALUE_BYTES = 16;

    public function __construct(
        private Randomizer $randomizer,
    ) {}

    /**
     * Exercise the vault, or report that there was none to exercise.
     *
     * @param TokenizationServiceInterface|null $vault The service that serves
     *        requests, resolved by the composition root. Null when the crypto
     *        stack never came up — which is a fact, not a pass
     * @param TokenStoreInterface|null          $store The same store the service
     *        writes through, needed to read the PERSISTED form back. Reading it
     *        through the service would only prove the service can decrypt what it
     *        encrypted, never that the bytes at rest conceal anything
     */
    #[NoDiscard]
    public function observe(?TokenizationServiceInterface $vault, ?TokenStoreInterface $store): Observation
    {
        if ($vault === null || $store === null) {
            return Observation::measured(
                ObservationId::TokenVaultRendersUnreadable,
                Measurement::couldNotRun(
                    self::SUBJECT,
                    'No token vault is in service, so nothing was exercised and nothing '
                        . 'renders a stored value unreadable.',
                ),
                self::class,
            );
        }

        return Observation::measured(
            ObservationId::TokenVaultRendersUnreadable,
            $this->exercise($vault, $store),
            self::class,
        );
    }

    /**
     * Run the subjects, cleaning up whatever was written.
     *
     * A throw from tokenize() is reported as a subject that RAN and failed, not
     * as a run that could not happen: the vault was called and it refused. That
     * is the distinction a missing `token_vault` table falls on, and collapsing
     * it would hide exactly the deployment this class was written for.
     */
    private function exercise(TokenizationServiceInterface $vault, TokenStoreInterface $store): Measurement
    {
        $secret = bin2hex($this->randomizer->getBytes(self::PROBE_VALUE_BYTES));

        try {
            $token = $vault->tokenize($secret, self::PROBE_CONTEXT);
        } catch (Throwable $failure) {
            return Measurement::completed(
                self::SUBJECT,
                [ExecutedSubject::failed(
                    'tokenize',
                    sprintf(
                        'The vault refused to tokenize a synthetic value: %s. Nothing this '
                            . 'deployment stores is rendered unreadable by it.',
                        $failure->getMessage(),
                    ),
                )],
                sprintf(
                    'The token vault is bound but not usable: tokenize() failed with %s.',
                    $failure->getMessage(),
                ),
            );
        }

        $results = [];

        try {
            $results[] = self::tokenConceals($token, $secret);
            $results[] = self::storedFormConceals($store, $token, $secret);
            $results[] = self::originalRecoverable($vault, $token, $secret);
        } finally {
            $results[] = self::mappingRemovable($store, $token);
        }

        return Measurement::completed(self::SUBJECT, $results, self::describe($results));
    }

    /**
     * The token that replaces the value must not carry the value.
     *
     * `str_contains` rather than inequality: format-preserving tokenization
     * legitimately keeps some digits, but a token that embeds the whole input has
     * replaced nothing.
     */
    private static function tokenConceals(string $token, string $secret): ExecutedSubject
    {
        return str_contains($token, $secret)
            ? ExecutedSubject::failed(
                'token conceals the value',
                'The token returned by the vault contains the value it was meant to replace.',
            )
            : ExecutedSubject::passed(
                'token conceals the value',
                'The token the vault returned carries none of the value it replaced.',
            );
    }

    /**
     * The bytes actually at rest must not carry the value.
     *
     * This is the requirement, literally: render the value unreadable ANYWHERE
     * IT IS STORED. It is read from the store rather than from the service so
     * that what is inspected is the persisted representation and not a round trip
     * through the same object that produced it.
     */
    private static function storedFormConceals(
        TokenStoreInterface $store,
        string $token,
        string $secret,
    ): ExecutedSubject {
        try {
            $stored = $store->retrieve($token);
        } catch (Throwable $failure) {
            return ExecutedSubject::failed(
                'stored form conceals the value',
                sprintf('The store refused to return what it had just persisted: %s.', $failure->getMessage()),
            );
        }

        if ($stored === null) {
            return ExecutedSubject::failed(
                'stored form conceals the value',
                'The store returned nothing for a token it had just been given, so the '
                    . 'mapping did not survive the call that created it.',
            );
        }

        return str_contains($stored, $secret)
            ? ExecutedSubject::failed(
                'stored form conceals the value',
                'The persisted record contains the value in the clear: the vault stores it readable.',
            )
            : ExecutedSubject::passed(
                'stored form conceals the value',
                sprintf(
                    'The persisted record is %d bytes and contains none of the value it stands for.',
                    strlen($stored),
                ),
            );
    }

    /**
     * A vault that cannot give the value back has not tokenized it; it has lost it.
     */
    private static function originalRecoverable(
        TokenizationServiceInterface $vault,
        string $token,
        string $secret,
    ): ExecutedSubject {
        try {
            $recovered = $vault->detokenize($token);
        } catch (Throwable $failure) {
            return ExecutedSubject::failed(
                'the original is recoverable',
                sprintf('Detokenizing the value the vault had just stored failed: %s.', $failure->getMessage()),
            );
        }

        return hash_equals($secret, $recovered)
            ? ExecutedSubject::passed(
                'the original is recoverable',
                'The vault returned the original value byte for byte, so the mapping is '
                    . 'reversible by its holder.',
            )
            : ExecutedSubject::failed(
                'the original is recoverable',
                'The vault returned something other than the value it was given, so what it '
                    . 'holds cannot be resolved back to the data it replaced.',
            );
    }

    /**
     * The row this measurement created must go away again.
     *
     * Asserted rather than assumed, and reported when it fails, because the
     * alternative is a report that quietly accumulates its own rows in the vault
     * it reports on.
     */
    private static function mappingRemovable(TokenStoreInterface $store, string $token): ExecutedSubject
    {
        try {
            $store->remove($token);
            $lingering = $store->exists($token);
        } catch (Throwable $failure) {
            return ExecutedSubject::failed(
                'the probe mapping is removed',
                sprintf(
                    'The synthetic mapping this check wrote could not be removed (%s); one row '
                        . 'under context "%s" is left in the vault.',
                    $failure->getMessage(),
                    self::PROBE_CONTEXT,
                ),
            );
        }

        return $lingering
            ? ExecutedSubject::failed(
                'the probe mapping is removed',
                sprintf(
                    'remove() reported success and the token still resolves; one row under '
                        . 'context "%s" is left in the vault.',
                    self::PROBE_CONTEXT,
                ),
            )
            : ExecutedSubject::passed(
                'the probe mapping is removed',
                'The synthetic mapping this check wrote was removed and no longer resolves.',
            );
    }

    /**
     * The sentence the report prints, naming what failed when something did.
     *
     * @param list<ExecutedSubject> $results
     *
     * @return non-empty-string
     */
    private static function describe(array $results): string
    {
        $failed = [];

        foreach ($results as $result) {
            if (!$result->passed) {
                $failed[] = $result->name . ': ' . $result->detail;
            }
        }

        return $failed === []
            ? sprintf(
                'A synthetic value was tokenized, stored, read back and detokenized through the '
                    . 'live vault: the persisted form concealed it, the original came back byte '
                    . 'for byte, and the probe mapping was removed. %d subject(s) ran.',
                count($results),
            )
            : 'The live vault did not render a stored value unreadable — ' . implode(' | ', $failed);
    }
}
