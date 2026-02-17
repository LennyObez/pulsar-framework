<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Sri;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Sri\SriAlgorithm;
use Pulsar\Security\Sri\SubresourceIntegrityHasher;
use RuntimeException;

use function base64_encode;
use function file_get_contents;
use function hash;
use function str_starts_with;

#[CoversClass(SubresourceIntegrityHasher::class)]
#[CoversClass(SriAlgorithm::class)]
final class SubresourceIntegrityHasherTest extends TestCase
{
    #[Test]
    public function hash_returns_sha384_prefix_by_default(): void
    {
        $hasher = new SubresourceIntegrityHasher();
        $result = $hasher->hash('body { color: red; }');

        self::assertTrue(str_starts_with($result, 'sha384-'));
    }

    #[Test]
    public function hash_produces_correct_sha256_value(): void
    {
        $content = 'alert("hello");';
        $expectedHash = base64_encode(hash('sha256', $content, binary: true));

        $hasher = new SubresourceIntegrityHasher(SriAlgorithm::Sha256);
        $result = $hasher->hash($content);

        self::assertSame('sha256-' . $expectedHash, $result);
    }

    #[Test]
    public function hash_produces_correct_sha384_value(): void
    {
        $content = 'console.log("test");';
        $expectedHash = base64_encode(hash('sha384', $content, binary: true));

        $hasher = new SubresourceIntegrityHasher(SriAlgorithm::Sha384);
        $result = $hasher->hash($content);

        self::assertSame('sha384-' . $expectedHash, $result);
    }

    #[Test]
    public function hash_produces_correct_sha512_value(): void
    {
        $content = 'document.ready();';
        $expectedHash = base64_encode(hash('sha512', $content, binary: true));

        $hasher = new SubresourceIntegrityHasher(SriAlgorithm::Sha512);
        $result = $hasher->hash($content);

        self::assertSame('sha512-' . $expectedHash, $result);
    }

    #[Test]
    public function hash_file_produces_same_result_as_hash(): void
    {
        // Use a known, stable project file to test hashFile without temp file management
        $filePath = __DIR__ . '/../../../../src/Security/Sri/SriAlgorithm.php';
        $content = file_get_contents($filePath);
        self::assertNotFalse($content);

        $hasher = new SubresourceIntegrityHasher(SriAlgorithm::Sha384);
        $fromContent = $hasher->hash($content);
        $fromFile = $hasher->hashFile($filePath);

        self::assertSame($fromContent, $fromFile);
    }

    #[Test]
    public function hash_file_throws_on_nonexistent_file(): void
    {
        $hasher = new SubresourceIntegrityHasher();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot read file for SRI hash');

        // Assign to suppress #[NoDiscard] -- exception is expected before return
        $_ = $hasher->hashFile('/nonexistent/path/to/file.js');
    }

    #[Test]
    public function multi_hash_returns_space_separated_values(): void
    {
        $content = 'var x = 1;';

        $result = SubresourceIntegrityHasher::multiHash($content, [
            SriAlgorithm::Sha256,
            SriAlgorithm::Sha384,
        ]);

        $parts = explode(' ', $result);
        self::assertCount(2, $parts);
        self::assertTrue(str_starts_with($parts[0], 'sha256-'));
        self::assertTrue(str_starts_with($parts[1], 'sha384-'));
    }

    #[Test]
    public function multi_hash_with_single_algorithm(): void
    {
        $content = 'test content';

        $result = SubresourceIntegrityHasher::multiHash($content, [SriAlgorithm::Sha512]);

        self::assertStringNotContainsString(' ', $result);
        self::assertTrue(str_starts_with($result, 'sha512-'));
    }

    #[Test]
    public function multi_hash_values_are_independently_correct(): void
    {
        $content = 'function foo() {}';

        $result = SubresourceIntegrityHasher::multiHash($content, [
            SriAlgorithm::Sha256,
            SriAlgorithm::Sha384,
            SriAlgorithm::Sha512,
        ]);

        $parts = explode(' ', $result);
        self::assertCount(3, $parts);

        // Verify each part independently
        $sha256Hasher = new SubresourceIntegrityHasher(SriAlgorithm::Sha256);
        $sha384Hasher = new SubresourceIntegrityHasher(SriAlgorithm::Sha384);
        $sha512Hasher = new SubresourceIntegrityHasher(SriAlgorithm::Sha512);

        self::assertSame($sha256Hasher->hash($content), $parts[0]);
        self::assertSame($sha384Hasher->hash($content), $parts[1]);
        self::assertSame($sha512Hasher->hash($content), $parts[2]);
    }

    #[Test]
    public function empty_content_produces_valid_hash(): void
    {
        $hasher = new SubresourceIntegrityHasher(SriAlgorithm::Sha256);
        $result = $hasher->hash('');

        $expected = 'sha256-' . base64_encode(hash('sha256', '', binary: true));
        self::assertSame($expected, $result);
    }

    #[Test]
    public function hash_is_deterministic(): void
    {
        $hasher = new SubresourceIntegrityHasher();
        $content = 'deterministic content test';

        $first = $hasher->hash($content);
        $second = $hasher->hash($content);

        self::assertSame($first, $second);
    }

    #[Test]
    public function different_content_produces_different_hash(): void
    {
        $hasher = new SubresourceIntegrityHasher();

        $hash1 = $hasher->hash('content A');
        $hash2 = $hasher->hash('content B');

        self::assertNotSame($hash1, $hash2);
    }

    #[Test]
    #[DataProvider('algorithmProvider')]
    public function all_algorithms_produce_valid_format(SriAlgorithm $algorithm): void
    {
        $hasher = new SubresourceIntegrityHasher($algorithm);
        $result = $hasher->hash('test');

        // SRI format: algorithm-base64
        self::assertMatchesRegularExpression(
            '/^(sha256|sha384|sha512)-[A-Za-z0-9+\/]+=*$/',
            $result,
        );
    }

    /** @return iterable<string, array{SriAlgorithm}> */
    public static function algorithmProvider(): iterable
    {
        yield 'sha256' => [SriAlgorithm::Sha256];
        yield 'sha384' => [SriAlgorithm::Sha384];
        yield 'sha512' => [SriAlgorithm::Sha512];
    }

    #[Test]
    public function sri_algorithm_enum_values_match_hash_function_names(): void
    {
        // Verify the enum values are valid PHP hash algorithm names
        foreach (SriAlgorithm::cases() as $algo) {
            $rawHash = hash($algo->value, 'test', binary: true);
            self::assertNotEmpty($rawHash, "Algorithm {$algo->value} must be a valid hash function");
        }
    }

    #[Test]
    public function multi_hash_with_empty_algorithms_returns_empty_string(): void
    {
        $result = SubresourceIntegrityHasher::multiHash('content', []);

        self::assertSame('', $result);
    }
}
