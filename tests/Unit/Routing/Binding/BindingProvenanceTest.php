<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing\Binding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Method;
use Pulsar\Routing\Binding\BindingProvenance;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;
use stdClass;

/**
 * The record a bound-model seal rests on.
 *
 * Every false answer here is a claim {@see \Pulsar\Routing\Binding\BoundModelArgumentResolver}
 * declines, and every true answer is an object it will seal onto a handler
 * parameter typed as an entity — so the questions this class can be made to
 * answer wrongly are the ones an attacker would ask.
 */
#[CoversClass(BindingProvenance::class)]
final class BindingProvenanceTest extends TestCase
{
    private ?MatchedRoute $dispatched = null;

    private ?MatchedRoute $otherDispatch = null;

    #[Test]
    public function attestsAModelItRecorded(): void
    {
        $model = new stdClass();

        $provenance = new BindingProvenance();
        $provenance->record($model, $this->route(), 'post', '41');

        self::assertTrue($provenance->attests($model, $this->route(), 'post', '41'));
    }

    #[Test]
    public function attestsNothingItNeverRecorded(): void
    {
        self::assertFalse(new BindingProvenance()->attests(new stdClass(), $this->route(), 'post', '41'));
    }

    /**
     * Per instance, not per class: the substitution a forged request attribute
     * makes is an equal-looking object, not the resolved one.
     */
    #[Test]
    public function attestsTheRecordedInstanceOnly(): void
    {
        $provenance = new BindingProvenance();
        $provenance->record(new stdClass(), $this->route(), 'post', '41');

        self::assertFalse($provenance->attests(new stdClass(), $this->route(), 'post', '41'));
    }

    #[Test]
    public function refusesAnotherParameterOfTheSameRequest(): void
    {
        $model = new stdClass();

        $provenance = new BindingProvenance();
        $provenance->record($model, $this->route(), 'post', '41');

        self::assertFalse($provenance->attests($model, $this->route(), 'comment', '41'));
    }

    #[Test]
    public function refusesAnotherRouteValueForTheSameParameter(): void
    {
        $model = new stdClass();

        $provenance = new BindingProvenance();
        $provenance->record($model, $this->route(), 'post', '41');

        self::assertFalse($provenance->attests($model, $this->route(), 'post', '9'));
    }

    /**
     * One object may legitimately be resolved for several parameters — a parent
     * that is also bound in its own right — and recording the second must not
     * retract the first.
     */
    #[Test]
    public function recordsAccumulateRatherThanReplace(): void
    {
        $model = new stdClass();

        $provenance = new BindingProvenance();
        $provenance->record($model, $this->route(), 'user', '7');
        $provenance->record($model, $this->route(), 'author', '7');

        self::assertTrue($provenance->attests($model, $this->route(), 'user', '7'));
        self::assertTrue($provenance->attests($model, $this->route(), 'author', '7'));
    }

    // ── An attestation belongs to the request that earned it ─────────────

    /**
     * THE REPLAY.
     *
     * Parameter and value alone left an attestation good for the life of the
     * OBJECT, and objects outlive requests: any persistence layer with an
     * identity map hands the same instance to every request that asks for that
     * row. So a `Post` one caller's request resolved and authorized could be put
     * into `_bound_models` on a LATER request, by a different caller, and be
     * sealed onto a handler parameter — the attestation was true, and it was
     * about a decision made for somebody else.
     */
    #[Test]
    public function refusesAnAttestationMintedForAnEarlierRequest(): void
    {
        $model = new stdClass();

        $provenance = new BindingProvenance();
        $provenance->record($model, $this->route(), 'post', '41');

        self::assertTrue($provenance->attests($model, $this->route(), 'post', '41'));

        $provenance->beginRequest();

        self::assertFalse(
            $provenance->attests($model, $this->route(), 'post', '41'),
            'an attestation must not outlive the request whose authorization produced it',
        );
    }

    /**
     * The same object, resolved again by the new request's own binding pass, is
     * attested again — otherwise closing the replay would break every route that
     * binds a row a previous request also bound.
     */
    #[Test]
    public function theNewRequestsOwnBindingIsAttestedAgain(): void
    {
        $model = new stdClass();

        $provenance = new BindingProvenance();
        $provenance->record($model, $this->route(), 'post', '41');
        $provenance->beginRequest();
        $provenance->record($model, $this->route(), 'post', '41');

        self::assertTrue($provenance->attests($model, $this->route(), 'post', '41'));
    }

    /**
     * A pass keeps only what it minted. The second request resolved the same
     * object for `{author}`, and that must not revive the `{user}` entry the
     * first request made for it — which is also what keeps the map's size a
     * function of one request rather than of an object's whole lifetime.
     */
    #[Test]
    public function aNewPassDoesNotInheritTheEntriesOfTheOldOne(): void
    {
        $model = new stdClass();

        $provenance = new BindingProvenance();
        $provenance->record($model, $this->route(), 'user', '7');

        $provenance->beginRequest();
        $provenance->record($model, $this->route(), 'author', '7');

        self::assertTrue($provenance->attests($model, $this->route(), 'author', '7'));
        self::assertFalse($provenance->attests($model, $this->route(), 'user', '7'));
    }

    /**
     * THE SEPARATOR FORGERY.
     *
     * Entries are keyed by parameter name and route value together, so a naive
     * `name:value` join would let a URL value carrying the separator produce the
     * same key as another parameter's entry — `{a}` = `b:c` against `{a:b}` =
     * `c`. Route values come from the URL, so that string is the caller's to
     * choose. The length prefix pins where the name ends before the value is
     * read at all.
     */
    #[Test]
    public function aRouteValueCannotImpersonateAnotherParametersEntry(): void
    {
        $model = new stdClass();

        $provenance = new BindingProvenance();
        $provenance->record($model, $this->route(), 'a', 'b:c');

        self::assertFalse($provenance->attests($model, $this->route(), 'a:b', 'c'));
        self::assertTrue($provenance->attests($model, $this->route(), 'a', 'b:c'));
    }

    // -----------------------------------------------------------------
    // The dispatch an entry names
    // -----------------------------------------------------------------

    /**
     * The forgery the parameter-and-value check cannot see.
     *
     * A frame inside the request can reach the binding middleware and bind it to
     * a route of its own — one carrying a legal `_without_authorization`
     * exemption, say — while copying the real route's parameter name and value
     * so that every value-level check still passes. What it cannot copy is the
     * MatchedRoute the kernel restores at the handler frame, and that is what
     * this answers about.
     */
    #[Test]
    public function refusesAModelMintedUnderAnotherDispatch(): void
    {
        $model = new stdClass();

        $provenance = new BindingProvenance();
        $provenance->record($model, $this->otherRoute(), 'post', '41');

        self::assertFalse(
            $provenance->attests($model, $this->route(), 'post', '41'),
            'Same parameter, same URL value, another route: the attestation is not this dispatch\'s.',
        );
    }

    #[Test]
    public function attestsTheDispatchItWasMintedUnder(): void
    {
        $model = new stdClass();

        $provenance = new BindingProvenance();
        $provenance->record($model, $this->otherRoute(), 'post', '41');

        self::assertTrue(
            $provenance->attests($model, $this->otherRoute(), 'post', '41'),
            'The mirror image, so the refusal above cannot pass by refusing everything.',
        );
    }

    /**
     * Two matches of the SAME route definition are two dispatches.
     *
     * The router allocates a MatchedRoute per match, so this is what separates
     * one request from the next even before the pass counter is consulted — and
     * it is the case a long-lived model held by an identity map would otherwise
     * carry an attestation across.
     */
    #[Test]
    public function refusesASecondMatchOfTheSameRouteDefinition(): void
    {
        $model = new stdClass();
        $definition = $this->definition();

        $first = new MatchedRoute($definition, ['post' => '41']);
        $second = new MatchedRoute($definition, ['post' => '41']);

        $provenance = new BindingProvenance();
        $provenance->record($model, $first, 'post', '41');

        self::assertFalse($provenance->attests($model, $second, 'post', '41'));
    }

    /**
     * A model re-minted under a new dispatch loses the entries of the old one,
     * so the map's size follows what the current dispatch bound rather than
     * everything a resident object has ever been bound as.
     */
    #[Test]
    public function aNewDispatchDoesNotInheritTheEntriesOfTheOldOne(): void
    {
        $model = new stdClass();

        $provenance = new BindingProvenance();
        $provenance->record($model, $this->route(), 'user', '7');
        $provenance->record($model, $this->otherRoute(), 'author', '7');

        self::assertFalse($provenance->attests($model, $this->otherRoute(), 'user', '7'));
        self::assertTrue($provenance->attests($model, $this->otherRoute(), 'author', '7'));
    }

    private function route(): MatchedRoute
    {
        return $this->dispatched ??= new MatchedRoute($this->definition(), ['post' => '41']);
    }

    /**
     * A second dispatch: another route entirely, matched for the same parameter
     * name and the same URL value, which is exactly the shape a forgery takes.
     */
    private function otherRoute(): MatchedRoute
    {
        return $this->otherDispatch ??= new MatchedRoute(
            new Route([Method::GET], '/open-posts/{post}', ProvenanceProbeController::class, 'posts.open'),
            ['post' => '41'],
        );
    }

    private function definition(): Route
    {
        return new Route([Method::GET], '/posts/{post}', ProvenanceProbeController::class, 'posts.show');
    }
}

/** A handler the probe routes can name. Never invoked. */
final class ProvenanceProbeController
{
    public function __invoke(): void {}
}
