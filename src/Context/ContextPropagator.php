<?php

declare(strict_types=1);

namespace Pulsar\Context;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Throwable;

use function array_key_exists;
use function is_string;

/**
 * Serializes/deserializes RequestContext into carrier arrays for cross-boundary propagation.
 *
 * Used to propagate context through queue job payloads, external HTTP calls,
 * and any other boundary that accepts key-value carriers.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ContextPropagator
{
    private const string KEY_CORRELATION_ID = '_ctx_correlation_id';
    private const string KEY_CAUSATION_ID = '_ctx_causation_id';
    private const string KEY_ACTOR = '_ctx_actor';
    private const string KEY_TENANT_ID = '_ctx_tenant_id';
    private const string KEY_IP = '_ctx_ip';
    private const string KEY_USER_AGENT = '_ctx_user_agent';
    private const string KEY_LOCALE = '_ctx_locale';
    private const string KEY_TIMESTAMP = '_ctx_timestamp';
    private const string KEY_ATTRIBUTES = '_ctx_attributes';

    /**
     * Inject request context into a carrier array.
     *
     * @param array<string, mixed> $carrier
     */
    public static function inject(RequestContext $context, array &$carrier): void
    {
        $carrier[self::KEY_CORRELATION_ID] = $context->correlationId->value;
        $carrier[self::KEY_CAUSATION_ID] = $context->causationId->value;
        $carrier[self::KEY_ACTOR] = $context->actor;
        $carrier[self::KEY_TENANT_ID] = $context->tenantId;
        $carrier[self::KEY_IP] = $context->ip;
        $carrier[self::KEY_USER_AGENT] = $context->userAgent;
        $carrier[self::KEY_LOCALE] = $context->locale;
        $carrier[self::KEY_TIMESTAMP] = $context->timestamp->format('Y-m-d\TH:i:s.uP');
        $carrier[self::KEY_ATTRIBUTES] = $context->attributes;
    }

    /**
     * Extract request context from a carrier array.
     *
     * Returns null if the carrier does not contain context data.
     *
     * @param array<string, mixed> $carrier
     */
    #[NoDiscard]
    public static function extract(array $carrier): ?RequestContext
    {
        if (!array_key_exists(self::KEY_CORRELATION_ID, $carrier)) {
            return null;
        }

        $correlationId = $carrier[self::KEY_CORRELATION_ID] ?? null;
        $causationId = $carrier[self::KEY_CAUSATION_ID] ?? null;

        if (!is_string($correlationId) || !is_string($causationId)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $attributes */
            $attributes = $carrier[self::KEY_ATTRIBUTES] ?? [];

            /** @var mixed $rawActor */
            $rawActor = $carrier[self::KEY_ACTOR] ?? null;
            /** @var mixed $rawTenantId */
            $rawTenantId = $carrier[self::KEY_TENANT_ID] ?? null;
            /** @var mixed $rawIp */
            $rawIp = $carrier[self::KEY_IP] ?? null;
            /** @var mixed $rawUserAgent */
            $rawUserAgent = $carrier[self::KEY_USER_AGENT] ?? null;
            /** @var mixed $rawLocale */
            $rawLocale = $carrier[self::KEY_LOCALE] ?? null;
            /** @var mixed $rawTimestamp */
            $rawTimestamp = $carrier[self::KEY_TIMESTAMP] ?? null;

            $actor = is_string($rawActor) ? $rawActor : null;
            $tenantId = is_string($rawTenantId) ? $rawTenantId : null;
            $ip = is_string($rawIp) ? $rawIp : null;
            $userAgent = is_string($rawUserAgent) ? $rawUserAgent : null;
            $locale = is_string($rawLocale) ? $rawLocale : null;
            $timestampRaw = is_string($rawTimestamp) ? $rawTimestamp : null;

            return new RequestContext(
                correlationId: CorrelationId::fromString($correlationId),
                causationId: CausationId::fromString($causationId),
                actor: $actor,
                tenantId: $tenantId,
                ip: $ip,
                userAgent: $userAgent,
                locale: $locale,
                timestamp: $timestampRaw !== null ? new DateTimeImmutable($timestampRaw) : null,
                attributes: $attributes,
            );
        } catch (Throwable) {
            return null;
        }
    }
}
