<?php

declare(strict_types=1);

namespace Pulsar\Auth\Password;

use InvalidArgumentException;
use Override;

use function password_hash;
use function password_needs_rehash;
use function password_verify;
use function sprintf;

use const PASSWORD_ARGON2ID;

/**
 * Argon2id password hasher using PHP's built-in password_hash().
 *
 * Defaults follow OWASP 2024 recommendations for Argon2id:
 * memory_cost=19456 (19 MiB), time_cost=2, threads=1.
 *
 * @see https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html
 */
final readonly class PasswordHasher implements PasswordHasherInterface
{
    /** OWASP 2024 recommended minimum: 19 MiB (19456 KiB). */
    public const int OWASP_MEMORY_COST = 19_456;

    /** OWASP 2024 recommended minimum iterations. */
    public const int OWASP_TIME_COST = 2;

    /** OWASP 2024 recommended thread count. */
    public const int OWASP_THREADS = 1;

    /** Absolute floor for memory_cost (15 MiB). Below this Argon2id provides insufficient resistance. */
    public const int MIN_MEMORY_COST = 15_360;

    /** Absolute floor for time_cost. */
    public const int MIN_TIME_COST = 2;

    /** @var array{memory_cost: int, time_cost: int, threads: int} */
    private array $options;

    /**
     * @param int  $memoryCost           Argon2id memory cost in KiB (default: 19456 / 19 MiB)
     * @param int  $timeCost             Argon2id iteration count (default: 2)
     * @param int  $threads              Argon2id parallelism degree (default: 1)
     * @param bool $allowWeakParameters  Set to true only in test environments to bypass floor validation
     */
    public function __construct(
        int $memoryCost = self::OWASP_MEMORY_COST,
        int $timeCost = self::OWASP_TIME_COST,
        int $threads = self::OWASP_THREADS,
        bool $allowWeakParameters = false,
    ) {
        if (!$allowWeakParameters) {
            if ($memoryCost < self::MIN_MEMORY_COST) {
                throw new InvalidArgumentException(sprintf(
                    'Argon2id memory_cost must be at least %d KiB (15 MiB), got %d. '
                    . 'Pass allowWeakParameters: true only in test environments to override.',
                    self::MIN_MEMORY_COST,
                    $memoryCost,
                ));
            }

            if ($timeCost < self::MIN_TIME_COST) {
                throw new InvalidArgumentException(sprintf(
                    'Argon2id time_cost must be at least %d, got %d. '
                    . 'Pass allowWeakParameters: true only in test environments to override.',
                    self::MIN_TIME_COST,
                    $timeCost,
                ));
            }
        }

        $this->options = [
            'memory_cost' => $memoryCost,
            'time_cost' => $timeCost,
            'threads' => $threads,
        ];
    }

    #[Override]
    public function hash(#[\SensitiveParameter] string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, $this->options);
    }

    #[Override]
    public function verify(#[\SensitiveParameter] string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    #[Override]
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, $this->options);
    }
}
