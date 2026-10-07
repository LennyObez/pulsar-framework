<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Egress;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\Egress\AiDestination;
use Pulsar\AI\Egress\AiEgressPolicy;
use Pulsar\AI\Exception\AiEgressRefusedException;
use Pulsar\Security\Dlp\DlpAction;

/**
 * The destination vocabulary and the operator's allow-list, on their own.
 *
 * The defaults are the substance here. A policy whose empty state permits
 * everything turns a forgotten config key into an open egress path, and the
 * deployment that forgets the key is the one this guard exists for.
 */
#[CoversClass(AiEgressPolicy::class)]
#[CoversClass(AiDestination::class)]
#[CoversClass(AiEgressRefusedException::class)]
final class AiEgressPolicyTest extends TestCase
{
    #[Test]
    public function theDefaultPolicyPermitsNothing(): void
    {
        $policy = new AiEgressPolicy();

        self::assertFalse($policy->permits(AiDestination::fromBaseUrl('ollama', 'http://localhost:11434')));
        self::assertFalse($policy->permits(
            AiDestination::fromBaseUrl('anthropic', 'https://api.anthropic.com/v1'),
        ));
    }

    #[Test]
    public function theDefaultActionOnClassifiedDataIsToBlockRatherThanToMask(): void
    {
        // Redaction still sends a request, still bills, still leaves a trace at
        // the endpoint, and changes what the model was asked. Blocking sends
        // nothing, so it is the conservative default.
        self::assertSame(DlpAction::Block, new AiEgressPolicy()->onSensitiveData);
        self::assertSame(DlpAction::Block, AiEgressPolicy::fromArray([])->onSensitiveData);
    }

    #[Test]
    public function aHostIsMatchedExactlyAndCaseInsensitively(): void
    {
        $policy = AiEgressPolicy::fromArray(['allowed_hosts' => ['API.Anthropic.Com']]);

        self::assertTrue($policy->permits(
            AiDestination::fromBaseUrl('anthropic', 'https://api.anthropic.com/v1'),
        ));
    }

    #[Test]
    public function aSubdomainOfAPermittedHostIsNotPermitted(): void
    {
        // No wildcards, deliberately: a pattern language on an egress allow-list
        // fails in the permissive direction.
        $policy = new AiEgressPolicy(allowedHosts: ['anthropic.com']);

        self::assertFalse($policy->permits(
            AiDestination::fromBaseUrl('anthropic', 'https://api.anthropic.com/v1'),
        ));
    }

    #[Test]
    public function theHostIsDerivedFromTheBaseUrlRatherThanDeclaredBesideIt(): void
    {
        $destination = AiDestination::fromBaseUrl('openai', 'https://API.OpenAI.com:443/v1');

        self::assertSame('api.openai.com', $destination->host);
        self::assertSame('openai', $destination->providerName);
        self::assertSame('openai @ api.openai.com', $destination->describe());
    }

    #[Test]
    public function aPortDoesNotChangeWhoReceivesTheBytes(): void
    {
        $policy = new AiEgressPolicy(allowedHosts: ['localhost']);

        self::assertTrue($policy->permits(AiDestination::fromBaseUrl('ollama', 'http://localhost:11434')));
        self::assertTrue($policy->permits(AiDestination::fromBaseUrl('ollama', 'http://localhost:9999')));
    }

    #[Test]
    public function aBaseUrlWrittenWithoutASchemeIsRefusedRatherThanReducedToAnEmptyHost(): void
    {
        // The operator writes the endpoint the way people say it out loud.
        // parse_url() then finds a PATH and no host, because without a scheme or
        // a leading '//' the whole string is a path. An empty host would fail
        // closed today for a reason nobody could see, and would be a wildcard
        // under any looser matcher added later.
        try {
            (void) AiDestination::fromBaseUrl('anthropic', 'api.anthropic.com/v1');
            self::fail('a schemeless base URL was accepted');
        } catch (AiEgressRefusedException $refusal) {
            self::assertStringContainsString('no host could be derived', $refusal->getMessage());
        }
    }

    #[Test]
    public function aHostWithAPortButNoSchemeStillParses(): void
    {
        // Checked rather than assumed: parse_url('localhost:11434') reports the
        // host and the port, not a scheme of 'localhost'.
        self::assertSame('localhost', AiDestination::fromBaseUrl('ollama', 'localhost:11434')->host);
    }

    #[Test]
    public function anUndeterminableDestinationCarriesNoDecisionBecauseThereIsNothingToDecideAbout(): void
    {
        try {
            (void) AiDestination::fromBaseUrl('ollama', '');
            self::fail('an empty base URL was accepted');
        } catch (AiEgressRefusedException $refusal) {
            self::assertNull($refusal->decision);
        }
    }

    #[Test]
    public function anUnreadableHostEntryIsDroppedRatherThanCoercedIntoTheList(): void
    {
        $policy = AiEgressPolicy::fromArray(['allowed_hosts' => ['', 'localhost']]);

        self::assertSame(['localhost'], $policy->allowedHosts);
    }
}
