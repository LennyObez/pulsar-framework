<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Transparency;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;

use function trim;

/**
 * The notice Article 50(1) requires a person to receive.
 *
 * Article 50(5) governs its delivery, and both of its requirements are properties
 * of this object rather than advice in a guide.
 *
 * "In a clear and distinguishable manner": the notice is held as text a
 * deployment renders, never as a boolean flag meaning "we told them somehow".
 * There is no way to satisfy this type without having written the sentence.
 *
 * "At the latest at the time of the first interaction or exposure": a disclosure
 * carries no schedule, because there is no later time it could name. Whatever
 * renders it must render it first, and the compliance control that observes this
 * asks the deployment to show where.
 *
 * The locale is required rather than defaulted. A notice a person cannot read is
 * not clear to that person, and Article 50(5) also requires conformity with the
 * accessibility requirements, which are not met by prose in a language chosen for
 * the operator's convenience.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class AiInteractionDisclosure
{
    /**
     * @param non-empty-string $notice the sentence a person is shown
     * @param non-empty-string $locale BCP 47 tag the notice is written in
     */
    public function __construct(
        public string $notice,
        public string $locale,
    ) {
        if (trim($this->notice) === '') {
            throw AiGovernanceException::disclosureNoticeEmpty();
        }

        if (trim($this->locale) === '') {
            throw AiGovernanceException::disclosureLocaleEmpty();
        }
    }

    /**
     * The notice in a form a machine-readable channel can carry.
     *
     * Article 50(1) is a duty owed to a person, so this is a convenience for
     * transports that have no other way to carry text — never a substitute for
     * rendering the notice where the person will read it.
     *
     * @return array{notice: string, locale: string}
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return ['notice' => $this->notice, 'locale' => $this->locale];
    }
}
