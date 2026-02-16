<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule\Regulated\Legal;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function in_array;
use function is_string;
use function sprintf;
use function strtoupper;

/**
 * Validates US state/territory jurisdiction codes.
 *
 * Accepts 50 US states + DC + US territories (AS, GU, MP, PR, VI).
 *
 * @see https://www.iso.org/obp/ui/#iso:code:3166:US
 */
#[Api(since: '1.0.0')]
readonly class JurisdictionCode implements RuleInterface
{
    /** @var list<string> Valid US jurisdiction codes */
    private const array CODES = [
        // 50 states
        'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'FL', 'GA',
        'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD',
        'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ',
        'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC',
        'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY',
        // DC
        'DC',
        // Territories
        'AS', 'GU', 'MP', 'PR', 'VI',
    ];

    public function __construct(
        private string $message = '',
    ) {}

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && in_array(strtoupper($value), self::CODES, true)) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf(
                'The %s field must be a valid US jurisdiction code.',
                $field,
            ),
            rule: $this->name(),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'jurisdiction_code';
    }
}
