<?php

declare(strict_types=1);

namespace Pulsar\Extension\Releases\Internal;

use DateTimeImmutable;
use InvalidArgumentException;
use OverflowException;
use Pulsar\Api\Internal;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Releases\BetaSignup;
use Pulsar\Extension\Releases\BetaSignupRepositoryInterface;
use Pulsar\Extension\Releases\DeviceType;
use Pulsar\Extension\Releases\Release;
use Pulsar\Extension\Releases\ReleasePlatform;
use Pulsar\Extension\Releases\ReleaseRepositoryInterface;

use function filter_var;
use function mb_strlen;

use const FILTER_VALIDATE_EMAIL;

/**
 * Core release domain service handling version management and beta signups.
 *
 * Enforces business rules:
 * - Email validation for beta signups
 * - Maximum 3 beta signup attempts per email per day (rate limiting)
 * - Version string length between 1 and 32 characters
 * - Release notes length between 1 and 50000 characters
 */
#[Internal(reason: 'Release domain service — use ReleaseRepositoryInterface for public API')]
final readonly class ReleaseService
{
    private const int MAX_SIGNUPS_PER_DAY = 3;
    private const int MAX_VERSION_LENGTH = 32;
    private const int MAX_RELEASE_NOTES_LENGTH = 50_000;

    public function __construct(
        private ReleaseRepositoryInterface $releaseRepository,
        private BetaSignupRepositoryInterface $betaSignupRepository,
    ) {}

    /**
     * Get the latest stable release for a platform.
     */
    public function getLatestVersion(ReleasePlatform $platform): ?Release
    {
        return $this->releaseRepository->findLatestStable($platform);
    }

    /**
     * List releases with optional platform and beta filters.
     *
     * @return PaginationResult<Release>
     */
    public function listReleases(
        int $page,
        int $perPage,
        ?ReleasePlatform $platform = null,
        ?bool $includeBeta = null,
    ): PaginationResult {
        return $this->releaseRepository->findAll($page, $perPage, $platform, $includeBeta);
    }

    /**
     * Register a beta signup.
     *
     * @param list<string> $cameraBrands
     *
     * @throws InvalidArgumentException If email is invalid
     * @throws OverflowException If daily signup rate limit is exceeded
     */
    public function signupForBeta(
        string $email,
        DeviceType $deviceType,
        array $cameraBrands,
    ): BetaSignup {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid email address');
        }

        $existing = $this->betaSignupRepository->findByEmail($email);

        if ($existing !== null) {
            throw new InvalidArgumentException('This email is already registered for beta');
        }

        $todayCount = $this->betaSignupRepository->countByEmailToday($email);

        if ($todayCount >= self::MAX_SIGNUPS_PER_DAY) {
            throw new OverflowException(
                'Rate limit exceeded: maximum '
                . self::MAX_SIGNUPS_PER_DAY
                . ' beta signup attempts per day',
            );
        }

        $signup = BetaSignup::create($email, $deviceType, $cameraBrands);
        $this->betaSignupRepository->save($signup);

        return $signup;
    }

    /**
     * Create a new release.
     *
     * @throws InvalidArgumentException If version or release notes are invalid
     */
    public function createRelease(
        string $version,
        ReleasePlatform $platform,
        DateTimeImmutable $releaseDate,
        string $releaseNotes,
        string $minimumOsVersion,
        ?string $downloadUrl = null,
        bool $isBeta = false,
        bool $isStable = false,
    ): Release {
        $versionLength = mb_strlen($version);

        if ($versionLength < 1 || $versionLength > self::MAX_VERSION_LENGTH) {
            throw new InvalidArgumentException(
                'Version must be between 1 and ' . self::MAX_VERSION_LENGTH . ' characters',
            );
        }

        $notesLength = mb_strlen($releaseNotes);

        if ($notesLength < 1 || $notesLength > self::MAX_RELEASE_NOTES_LENGTH) {
            throw new InvalidArgumentException(
                'Release notes must be between 1 and ' . self::MAX_RELEASE_NOTES_LENGTH . ' characters',
            );
        }

        $release = Release::create(
            $version,
            $platform,
            $releaseDate,
            $releaseNotes,
            $minimumOsVersion,
            $downloadUrl,
            $isBeta,
            $isStable,
        );
        $this->releaseRepository->save($release);

        return $release;
    }

    /**
     * Mark an existing release as stable.
     */
    public function markAsStable(string $id): ?Release
    {
        $release = $this->releaseRepository->findById($id);

        if ($release === null) {
            return null;
        }

        $updated = $release->markStable();
        $this->releaseRepository->save($updated);

        return $updated;
    }
}
