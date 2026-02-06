<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark;

use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;
use Pulsar\Http\Validation\Rule\Between;
use Pulsar\Http\Validation\Rule\Email;
use Pulsar\Http\Validation\Rule\IntegerType;
use Pulsar\Http\Validation\Rule\MaxLength;
use Pulsar\Http\Validation\Rule\MinLength;
use Pulsar\Http\Validation\Rule\Required;
use Pulsar\Http\Validation\Rule\StringType;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Validator;

/**
 * Benchmarks for request validation performance.
 *
 * Covers simple single-rule fields, complex multi-rule fields,
 * and validation that produces violations (error path).
 */
#[BeforeMethods('setUp')]
#[Revs(1000)]
#[Iterations(5)]
#[Warmup(1)]
final class ValidationBench
{
    private Validator $validator;

    /** @var array<string, mixed> */
    private array $simpleData;

    /** @var array<string, list<RuleInterface>> */
    private array $simpleRules;

    /** @var array<string, mixed> */
    private array $complexData;

    /** @var array<string, list<RuleInterface>> */
    private array $complexRules;

    /** @var array<string, mixed> */
    private array $failingData;

    /** @var array<string, list<RuleInterface>> */
    private array $failingRules;

    public function setUp(): void
    {
        $this->validator = new Validator();

        // Simple: 5 fields, 1 rule each
        $this->simpleData = [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'age' => 30,
            'city' => 'Portland',
            'country' => 'US',
        ];
        $this->simpleRules = [
            'name' => [new Required()],
            'email' => [new Email()],
            'age' => [new IntegerType()],
            'city' => [new StringType()],
            'country' => [new Required()],
        ];

        // Complex: 10 fields, multiple rules each
        $this->complexData = [
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'email' => 'alice@example.com',
            'password' => 'SecureP@ss1',
            'age' => 30,
            'bio' => 'A short biography for testing purposes.',
            'city' => 'Portland',
            'zip_code' => '97201',
            'phone' => '5551234567',
            'score' => 85,
        ];
        $this->complexRules = [
            'first_name' => [new Required(), new StringType(), new MinLength(2), new MaxLength(50)],
            'last_name' => [new Required(), new StringType(), new MinLength(2), new MaxLength(50)],
            'email' => [new Required(), new StringType(), new Email()],
            'password' => [new Required(), new StringType(), new MinLength(8), new MaxLength(128)],
            'age' => [new Required(), new IntegerType(), new Between(1, 150)],
            'bio' => [new StringType(), new MaxLength(500)],
            'city' => [new Required(), new StringType(), new MinLength(2)],
            'zip_code' => [new Required(), new StringType(), new MinLength(5), new MaxLength(10)],
            'phone' => [new Required(), new StringType(), new MinLength(10), new MaxLength(15)],
            'score' => [new Required(), new IntegerType(), new Between(0, 100)],
        ];

        // Failing: data that violates rules to exercise the error path
        $this->failingData = [
            'name' => '',
            'email' => 'not-an-email',
            'age' => 'abc',
            'password' => 'short',
            'score' => 200,
        ];
        $this->failingRules = [
            'name' => [new Required(), new StringType(), new MinLength(2)],
            'email' => [new Required(), new StringType(), new Email()],
            'age' => [new Required(), new IntegerType()],
            'password' => [new Required(), new StringType(), new MinLength(8)],
            'score' => [new Required(), new IntegerType(), new Between(0, 100)],
        ];
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 50 microseconds')]
    public function benchSimpleValidation(): void
    {
        $result = $this->validator->validate($this->simpleData, $this->simpleRules);
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 200 microseconds')]
    public function benchComplexValidation(): void
    {
        $result = $this->validator->validate($this->complexData, $this->complexRules);
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 100 microseconds')]
    public function benchValidationWithViolations(): void
    {
        $result = $this->validator->validate($this->failingData, $this->failingRules);
    }
}
