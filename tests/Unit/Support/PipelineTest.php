<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Support\Pipeline;

use function assert;
use function is_array;
use function is_int;
use function is_string;
use function strlen;

final class PipelineTest extends TestCase
{
    #[Test]
    public function thenReturnRunsThroughAllStages(): void
    {
        $result = Pipeline::send('  HELLO WORLD  ')
            ->through([
                static function (mixed $s): string {
                    assert(is_string($s));
                    return trim($s);
                },
                static function (mixed $s): string {
                    assert(is_string($s));
                    return strtolower($s);
                },
                static function (mixed $s): string {
                    assert(is_string($s));
                    return str_replace(' ', '-', $s);
                },
            ])
            ->thenReturn();

        self::assertSame('hello-world', $result);
    }

    #[Test]
    public function thenReturnWithNoStagesReturnsOriginal(): void
    {
        $result = Pipeline::send(42)
            ->through([])
            ->thenReturn();

        self::assertSame(42, $result);
    }

    #[Test]
    public function pipeAddsIndividualStage(): void
    {
        $result = Pipeline::send(10)
            ->pipe(static function (mixed $n): int {
                assert(is_int($n));
                return $n + 5;
            })
            ->pipe(static function (mixed $n): int {
                assert(is_int($n));
                return $n * 2;
            })
            ->thenReturn();

        self::assertSame(30, $result);
    }

    #[Test]
    public function thenAppliesFinalDestination(): void
    {
        $result = Pipeline::send('hello')
            ->through([
                static function (mixed $s): string {
                    assert(is_string($s));
                    return strtoupper($s);
                },
            ])
            ->then(static function (mixed $s): int {
                assert(is_string($s));
                return strlen($s);
            });

        self::assertSame(5, $result);
    }

    #[Test]
    public function pipelineWorksWithArrayData(): void
    {
        $result = Pipeline::send([1, 2, 3])
            ->pipe(static function (mixed $arr): array {
                assert(is_array($arr));
                return array_map(static function (mixed $n): int {
                    assert(is_int($n));
                    return $n * 2;
                }, $arr);
            })
            ->pipe(static function (mixed $arr): array {
                assert(is_array($arr));
                return array_filter($arr, static function (mixed $n): bool {
                    assert(is_int($n));
                    return $n > 3;
                });
            })
            ->pipe(static function (mixed $arr): array {
                assert(is_array($arr));
                return array_values($arr);
            })
            ->thenReturn();

        self::assertSame([4, 6], $result);
    }

    #[Test]
    public function pipelineCombinesThroughAndPipe(): void
    {
        $result = Pipeline::send(5)
            ->through([
                static function (mixed $n): int {
                    assert(is_int($n));
                    return $n + 1;
                },
            ])
            ->pipe(static function (mixed $n): int {
                assert(is_int($n));
                return $n * 3;
            })
            ->thenReturn();

        self::assertSame(18, $result);
    }

    #[Test]
    public function pipelineHandlesNullData(): void
    {
        $result = Pipeline::send(null)
            ->pipe(fn(mixed $v): string => $v === null ? 'null' : 'not null')
            ->thenReturn();

        self::assertSame('null', $result);
    }
}
