<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Security\Exception\SecurityException;
use Random\Engine\Secure;
use Random\Randomizer;
use SensitiveParameter;
use SodiumException;

use function bin2hex;
use function ctype_digit;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Tokenization service for PCI-DSS Requirement 3.4 compliance.
 *
 * Replaces sensitive data (PANs, SSNs, etc.) with non-sensitive tokens while
 * storing the encrypted original in a configurable token store. Supports
 * format-preserving tokenization for PANs (preserves first 6 / last 4 digits).
 *
 * Token format: "ptk_" || hex(random_bytes)
 * PAN token format: first6 || random_digits || last4 (same length as original PAN)
 *
 * Sub-key ID: 7, KDF context: 'tokenize'
 * @api
 */
#[Api(since: '1.0.0')]
final class TokenizationService implements TokenizationServiceInterface
{
    private const int SUB_KEY_ID = 7;
    private const string KDF_CONTEXT = 'tokenize';

    /**
     * Prefix for standard (non-PAN) tokens.
     */
    private const string TOKEN_PREFIX = 'ptk_';

    /**
     * Length of the random hex portion of a standard token (16 bytes = 32 hex chars).
     */
    private const int TOKEN_RANDOM_BYTES = 16;

    /**
     * PAN context identifier: triggers format-preserving tokenization.
     */
    private const string PAN_CONTEXT = 'pan';

    /**
     * Minimum PAN length (13 digits per ISO/IEC 7812).
     */
    private const int PAN_MIN_LENGTH = 13;

    /**
     * Maximum PAN length (19 digits per ISO/IEC 7812).
     */
    private const int PAN_MAX_LENGTH = 19;

    /**
     * Number of leading PAN digits to preserve (BIN/IIN).
     */
    private const int PAN_PRESERVE_FIRST = 6;

    /**
     * Number of trailing PAN digits to preserve.
     */
    private const int PAN_PRESERVE_LAST = 4;

    private readonly Encryptor $encryptor;
    private readonly Randomizer $randomizer;

    /**
     * @throws SodiumException
     */
    public function __construct(
        MasterKey $masterKey,
        private readonly TokenStoreInterface $store,
        ?CipherSuiteInterface $cipherSuite = null,
    ) {
        $this->encryptor = Encryptor::fromDerivedKey(
            $masterKey,
            self::SUB_KEY_ID,
            self::KDF_CONTEXT,
            $cipherSuite,
        );
        $this->randomizer = new Randomizer(new Secure());
    }

    public function tokenize(
        #[SensitiveParameter]
        string $sensitiveData,
        string $context,
    ): string {
        if ($sensitiveData === '') {
            throw SecurityException::encryptionFailed('Cannot tokenize empty data');
        }

        if ($context === self::PAN_CONTEXT) {
            return $this->tokenizePan($sensitiveData);
        }

        return $this->tokenizeGeneric($sensitiveData, $context);
    }

    public function detokenize(string $token): string
    {
        $encrypted = $this->store->retrieve($token);

        if ($encrypted === null) {
            throw SecurityException::decryptionFailed();
        }

        return $this->encryptor->decrypt($encrypted);
    }

    #[NoDiscard]
    public function isTokenized(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        // Standard tokens start with the prefix
        if (str_starts_with($value, self::TOKEN_PREFIX)) {
            return $this->store->exists($value);
        }

        // PAN tokens are all-digit strings that exist in the store
        if (ctype_digit($value) && strlen($value) >= self::PAN_MIN_LENGTH && strlen($value) <= self::PAN_MAX_LENGTH) {
            return $this->store->exists($value);
        }

        return false;
    }

    /**
     * Format-preserving PAN tokenization.
     *
     * Preserves first 6 (BIN/IIN) and last 4 digits. Middle digits are replaced
     * with random digits. The full PAN is encrypted and stored in the token vault.
     */
    private function tokenizePan(
        #[SensitiveParameter]
        string $pan,
    ): string {
        $digits = $pan;

        if (!ctype_digit($digits) || strlen($digits) < self::PAN_MIN_LENGTH || strlen($digits) > self::PAN_MAX_LENGTH) {
            throw SecurityException::encryptionFailed(
                sprintf('Invalid PAN: must be %d–%d digits', self::PAN_MIN_LENGTH, self::PAN_MAX_LENGTH),
            );
        }

        $first = substr($digits, 0, self::PAN_PRESERVE_FIRST);
        $last = substr($digits, -self::PAN_PRESERVE_LAST);
        $middleLength = strlen($digits) - self::PAN_PRESERVE_FIRST - self::PAN_PRESERVE_LAST;

        // Generate random middle digits
        $middle = $this->generateRandomDigits($middleLength);
        $token = $first . $middle . $last;

        // Avoid collisions (extremely unlikely but handle gracefully)
        $attempts = 0;
        while ($this->store->exists($token) && $attempts < 10) {
            $middle = $this->generateRandomDigits($middleLength);
            $token = $first . $middle . $last;
            $attempts++;
        }

        if ($this->store->exists($token)) {
            throw SecurityException::encryptionFailed('Failed to generate unique PAN token after maximum attempts');
        }

        $encrypted = $this->encryptor->encrypt($pan);
        $this->store->store($token, $encrypted, self::PAN_CONTEXT);

        return $token;
    }

    /**
     * Generic tokenization for non-PAN sensitive data.
     */
    private function tokenizeGeneric(
        #[SensitiveParameter]
        string $sensitiveData,
        string $context,
    ): string {
        $randomHex = bin2hex($this->randomizer->getBytes(self::TOKEN_RANDOM_BYTES));
        $token = self::TOKEN_PREFIX . $randomHex;

        // Avoid collisions
        $attempts = 0;
        while ($this->store->exists($token) && $attempts < 10) {
            $randomHex = bin2hex($this->randomizer->getBytes(self::TOKEN_RANDOM_BYTES));
            $token = self::TOKEN_PREFIX . $randomHex;
            $attempts++;
        }

        if ($this->store->exists($token)) {
            throw SecurityException::encryptionFailed('Failed to generate unique token after maximum attempts');
        }

        $encrypted = $this->encryptor->encrypt($sensitiveData);
        $this->store->store($token, $encrypted, $context);

        return $token;
    }

    /**
     * Generate a string of random decimal digits.
     */
    private function generateRandomDigits(int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $digits = '';
        for ($i = 0; $i < $length; $i++) {
            $digits .= (string) $this->randomizer->getInt(0, 9);
        }

        return $digits;
    }
}
