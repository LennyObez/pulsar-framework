<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation;

use BackedEnum;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\Rule\After;
use Pulsar\Http\Validation\Rule\Alpha;
use Pulsar\Http\Validation\Rule\AlphaNumeric;
use Pulsar\Http\Validation\Rule\ArrayType;
use Pulsar\Http\Validation\Rule\Ascii;
use Pulsar\Http\Validation\Rule\Before;
use Pulsar\Http\Validation\Rule\Between;
use Pulsar\Http\Validation\Rule\Bic;
use Pulsar\Http\Validation\Rule\BooleanType;
use Pulsar\Http\Validation\Rule\Confirmed;
use Pulsar\Http\Validation\Rule\Contains;
use Pulsar\Http\Validation\Rule\CountryCode;
use Pulsar\Http\Validation\Rule\CreditCard;
use Pulsar\Http\Validation\Rule\CurrencyCode;
use Pulsar\Http\Validation\Rule\Date;
use Pulsar\Http\Validation\Rule\DateBetween;
use Pulsar\Http\Validation\Rule\DateTimeRule;
use Pulsar\Http\Validation\Rule\Decimal;
use Pulsar\Http\Validation\Rule\Different;
use Pulsar\Http\Validation\Rule\Distinct;
use Pulsar\Http\Validation\Rule\Divisible;
use Pulsar\Http\Validation\Rule\Email;
use Pulsar\Http\Validation\Rule\EndsWith;
use Pulsar\Http\Validation\Rule\EnumRule;
use Pulsar\Http\Validation\Rule\FileRule;
use Pulsar\Http\Validation\Rule\GreaterThan;
use Pulsar\Http\Validation\Rule\Iban;
use Pulsar\Http\Validation\Rule\Image;
use Pulsar\Http\Validation\Rule\In;
use Pulsar\Http\Validation\Rule\InstanceOfRule;
use Pulsar\Http\Validation\Rule\IntegerType;
use Pulsar\Http\Validation\Rule\Ip;
use Pulsar\Http\Validation\Rule\Json;
use Pulsar\Http\Validation\Rule\KeyExists;
use Pulsar\Http\Validation\Rule\LanguageCode;
use Pulsar\Http\Validation\Rule\LessThan;
use Pulsar\Http\Validation\Rule\Max;
use Pulsar\Http\Validation\Rule\MaxFileSize;
use Pulsar\Http\Validation\Rule\MaxLength;
use Pulsar\Http\Validation\Rule\Mimes;
use Pulsar\Http\Validation\Rule\Min;
use Pulsar\Http\Validation\Rule\MinLength;
use Pulsar\Http\Validation\Rule\Negative;
use Pulsar\Http\Validation\Rule\NotContains;
use Pulsar\Http\Validation\Rule\Nullable;
use Pulsar\Http\Validation\Rule\Phone;
use Pulsar\Http\Validation\Rule\Positive;
use Pulsar\Http\Validation\Rule\PostalCode;
use Pulsar\Http\Validation\Rule\Regex;
use Pulsar\Http\Validation\Rule\Required;
use Pulsar\Http\Validation\Rule\RequiredIf;
use Pulsar\Http\Validation\Rule\RequiredUnless;
use Pulsar\Http\Validation\Rule\RequiredWith;
use Pulsar\Http\Validation\Rule\RequiredWithout;
use Pulsar\Http\Validation\Rule\Same;
use Pulsar\Http\Validation\Rule\Size;
use Pulsar\Http\Validation\Rule\Slug;
use Pulsar\Http\Validation\Rule\StartsWith;
use Pulsar\Http\Validation\Rule\StringType;
use Pulsar\Http\Validation\Rule\Timezone;
use Pulsar\Http\Validation\Rule\Url;
use Pulsar\Http\Validation\Rule\Uuid;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function is_string;
use function is_subclass_of;
use function str_contains;
use function trim;

/**
 * Fluent validation builder for expressive inline validation.
 *
 * Usage:
 *   $result = ValidatorBuilder::make($data)
 *       ->rule('email', 'required|email')
 *       ->rule('name', 'required|string|max_length:255')
 *       ->validate();
 * @api
 */
#[Api(since: '1.0.0')]
final class ValidatorBuilder
{
    /** @var array<string, list<RuleInterface>> */
    private array $rules = [];

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        private readonly array $data,
    ) {}

    /**
     * Create a builder for the given data.
     *
     * @param array<string, mixed> $data
     */
    public static function make(array $data): self
    {
        return new self($data);
    }

    /**
     * Add rules for a field.
     *
     * Rules can be a pipe-separated string (e.g. "required|email|max_length:255")
     * or a list of RuleInterface objects.
     *
     * @param string|list<RuleInterface> $rules
     *
     * @return $this
     */
    public function rule(string $field, string|array $rules): self
    {
        if (is_string($rules)) {
            $this->rules[$field] = [
                ...($this->rules[$field] ?? []),
                ...self::parseStringRules($rules),
            ];
        } else {
            $this->rules[$field] = [
                ...($this->rules[$field] ?? []),
                ...$rules,
            ];
        }

        return $this;
    }

    /**
     * Add rules for multiple fields at once.
     *
     * @param array<string, string|list<RuleInterface>> $rules
     *
     * @return $this
     */
    public function rules(array $rules): self
    {
        foreach ($rules as $field => $fieldRules) {
            $this->rule($field, $fieldRules);
        }

        return $this;
    }

    /**
     * Validate and return the result.
     */
    #[NoDiscard]
    public function validate(): ValidationResult
    {
        $validator = new Validator();

        return $validator->validate($this->data, $this->rules);
    }

    /**
     * Validate and throw on failure.
     *
     * @throws ValidationException
     */
    #[NoDiscard]
    public function validateOrFail(): ValidationResult
    {
        $validator = new Validator();

        return $validator->validateOrFail($this->data, $this->rules);
    }

    /**
     * Parse a pipe-separated rule string into RuleInterface objects.
     *
     * @return list<RuleInterface>
     */
    private static function parseStringRules(string $rules): array
    {
        $parts = explode('|', $rules);

        return array_values(array_map(self::parseRule(...), $parts));
    }

    /**
     * Parse a single rule string like "required", "max_length:255", "in:a,b,c".
     *
     * Every shipped rule with a meaningful string form is mapped here. Rules that
     * cannot be expressed as a string — Exists/Unique (need a database query
     * port), Each (nested RuleInterface objects), Dimensions (image-geometry
     * object) — have no DSL form and must be supplied via the object API; a
     * string name for them falls through to CustomStringRule.
     */
    private static function parseRule(string $rule): RuleInterface
    {
        $segments = explode(':', $rule, 2);
        $name = trim($segments[0]);
        $param = $segments[1] ?? '';

        return match ($name) {
            // No-parameter rules.
            'required' => new Required(),
            'nullable' => new Nullable(),
            'email' => new Email(),
            'url' => new Url(),
            'string' => new StringType(),
            'integer' => new IntegerType(),
            'boolean' => new BooleanType(),
            'array' => new ArrayType(),
            'json' => new Json(),
            'uuid' => new Uuid(),
            'slug' => new Slug(),
            'ascii' => new Ascii(),
            'alpha' => new Alpha(),
            'alpha_numeric' => new AlphaNumeric(),
            'positive' => new Positive(),
            'negative' => new Negative(),
            'distinct' => new Distinct(),
            'confirmed' => new Confirmed(),
            'credit_card' => new CreditCard(),
            'iban' => new Iban(),
            'bic' => new Bic(),
            'phone' => new Phone(),
            'timezone' => new Timezone(),
            'country_code' => new CountryCode(),
            'currency_code' => new CurrencyCode(),
            'language_code' => new LanguageCode(),
            'image' => new Image(),
            'file' => new FileRule(),

            // Integer-parameter rules.
            'min' => new Min((int) $param),
            'max' => new Max((int) $param),
            'min_length' => new MinLength((int) $param),
            'max_length' => new MaxLength((int) $param),
            'size' => new Size((int) $param),
            'max_file_size' => new MaxFileSize((int) $param),

            // Numeric (int or float) parameter rules.
            'greater_than' => new GreaterThan(self::numeric($param)),
            'less_than' => new LessThan(self::numeric($param)),
            'divisible' => new Divisible(self::numeric($param)),

            // String-parameter rules.
            'regex' => new Regex($param),
            'contains' => new Contains($param),
            'not_contains' => new NotContains($param),
            'starts_with' => new StartsWith($param),
            'ends_with' => new EndsWith($param),
            'same' => new Same($param),
            'different' => new Different($param),
            'instance_of' => new InstanceOfRule($param),
            'enum' => self::enumRule($param),
            'after' => new After($param),
            'before' => new Before($param),

            // Optional-parameter rules (sensible default when no argument given).
            'date' => $param === '' ? new Date() : new Date($param),
            'date_time' => $param === '' ? new DateTimeRule() : new DateTimeRule($param),
            'ip' => $param === '' ? new Ip() : new Ip($param),
            'postal_code' => $param === '' ? new PostalCode() : new PostalCode($param),

            // Comma-separated list rules.
            'in' => new In(self::splitList($param)),
            'mimes' => new Mimes(...self::splitList($param)),
            'key_exists' => new KeyExists(...self::splitList($param)),
            'required_with' => new RequiredWith(...self::splitList($param)),
            'required_without' => new RequiredWithout(...self::splitList($param)),

            // Two-argument rules.
            'between' => self::betweenRule($param),
            'date_between' => self::dateBetweenRule($param),
            'decimal' => self::decimalRule($param),
            'required_if' => self::requiredFieldRule($param, true),
            'required_unless' => self::requiredFieldRule($param, false),

            default => new CustomStringRule($name, $param),
        };
    }

    /**
     * Parse a DSL numeric argument as an int, or a float when it has a fraction.
     */
    private static function numeric(string $param): int|float
    {
        return str_contains($param, '.') ? (float) $param : (int) $param;
    }

    /**
     * Split a comma-separated DSL argument into a trimmed, non-empty value list.
     *
     * @return list<string>
     */
    private static function splitList(string $param): array
    {
        return array_values(array_filter(
            array_map(trim(...), explode(',', $param)),
            static fn(string $value): bool => $value !== '',
        ));
    }

    private static function betweenRule(string $param): Between
    {
        $parts = explode(',', $param, 2);

        return new Between(self::numeric(trim($parts[0])), self::numeric(trim($parts[1] ?? '')));
    }

    private static function dateBetweenRule(string $param): DateBetween
    {
        $parts = explode(',', $param, 2);

        return new DateBetween(trim($parts[0]), trim($parts[1] ?? ''));
    }

    private static function decimalRule(string $param): Decimal
    {
        $parts = explode(',', $param, 2);
        $min = (int) trim($parts[0]);

        return isset($parts[1]) ? new Decimal($min, (int) trim($parts[1])) : new Decimal($min);
    }

    /**
     * Build an enum rule from a DSL class name. The name must resolve to a
     * backed enum; anything else falls through to CustomStringRule so the
     * invalid configuration surfaces as a validation error rather than a type
     * error at construction.
     */
    private static function enumRule(string $param): RuleInterface
    {
        return is_subclass_of($param, BackedEnum::class)
            ? new EnumRule($param)
            : new CustomStringRule('enum', $param);
    }

    private static function requiredFieldRule(string $param, bool $required): RuleInterface
    {
        $parts = explode(',', $param, 2);
        $field = trim($parts[0]);
        $value = trim($parts[1] ?? '');

        return $required
            ? new RequiredIf($field, $value)
            : new RequiredUnless($field, $value);
    }
}
