<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Ppa\Repository;

use ArrayIterator;
use Flytachi\Winter\Kernel\Ppa\Repository\RepositoryCrudTrait;
use Generator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The rule `insertBatch()` reads its arguments by: **an array is one row, anything
 * traversable is a stream of rows.**
 *
 * The asymmetry is deliberate and unambiguous — an array is a legal entity in this API
 * (a column-value map, the same form `insert()` takes), and a `Traversable` never is.
 * That is what lets one variadic accept entities, unpacked arrays and generators in any
 * combination, instead of needing a second method for the streaming case.
 *
 * Laziness is the other half. Rows must reach the driver as they are produced; building
 * them into an array here would put the whole job back in memory and undo the reason the
 * shape exists — 500 000 entities cost 440 MiB collected against 4 MiB streamed.
 */
final class InsertBatchFlattenTest extends TestCase
{
    /** @return list<mixed> */
    private function flatten(mixed ...$args): array
    {
        $flatten = new ReflectionMethod(RepositoryCrudTrait::class, 'flatten');

        return iterator_to_array($flatten->invoke(null, $args), false);
    }

    // ── The rule ───────────────────────────────────────────────────────────────

    public function test_objects_pass_through_one_by_one(): void
    {
        $a = new \stdClass();
        $b = new \stdClass();

        self::assertSame([$a, $b], $this->flatten($a, $b));
    }

    /** The half that could not be guessed: an array is a row, not a collection. */
    public function test_an_array_is_one_row(): void
    {
        $row = ['name' => 'John', 'email' => 'j@x'];

        self::assertSame([$row], $this->flatten($row), 'an array must not be drained');
    }

    public function test_several_arrays_are_several_rows(): void
    {
        $first  = ['name' => 'John'];
        $second = ['name' => 'Jane'];

        self::assertSame([$first, $second], $this->flatten($first, $second));
    }

    public function test_a_generator_is_drained(): void
    {
        $stream = (static function (): Generator {
            yield ['n' => 1];
            yield ['n' => 2];
            yield ['n' => 3];
        })();

        self::assertSame([['n' => 1], ['n' => 2], ['n' => 3]], $this->flatten($stream));
    }

    public function test_any_traversable_is_drained(): void
    {
        self::assertSame(
            [['n' => 1], ['n' => 2]],
            $this->flatten(new ArrayIterator([['n' => 1], ['n' => 2]])),
        );
    }

    public function test_the_forms_combine_in_one_call(): void
    {
        $entity = new \stdClass();
        $stream = (static function (): Generator {
            yield ['n' => 1];
            yield ['n' => 2];
        })();

        self::assertSame(
            [$entity, ['n' => 1], ['n' => 2], ['extra' => true]],
            $this->flatten($entity, $stream, ['extra' => true]),
        );
    }

    public function test_two_streams_are_drained_in_order(): void
    {
        $first  = (static function (): Generator {
            yield 'a';
            yield 'b';
        })();
        $second = (static function (): Generator {
            yield 'c';
        })();

        self::assertSame(['a', 'b', 'c'], $this->flatten($first, $second));
    }

    public function test_no_arguments_yield_nothing(): void
    {
        self::assertSame([], $this->flatten());
    }

    public function test_an_empty_stream_contributes_nothing(): void
    {
        $empty = (static function (): Generator {
            if (false) {
                yield 1;
            }
        })();

        self::assertSame([['kept' => true]], $this->flatten($empty, ['kept' => true]));
    }

    // ── Laziness: the reason the shape exists ──────────────────────────────────

    /** Nothing may be pulled from the source until the driver asks for it. */
    public function test_the_source_is_untouched_until_iterated(): void
    {
        $produced = 0;
        $stream = (static function () use (&$produced): Generator {
            for ($i = 0; $i < 5; $i++) {
                $produced++;
                yield ['n' => $i];
            }
        })();

        $flatten = new ReflectionMethod(RepositoryCrudTrait::class, 'flatten');
        $rows = $flatten->invoke(null, [$stream]);

        self::assertSame(0, $produced, 'building the stream must not consume the source');

        $rows->current();
        self::assertSame(1, $produced, 'exactly one row is produced to satisfy one read');
    }

    /** …and each row is produced exactly once, however many streams are involved. */
    public function test_each_row_is_produced_once(): void
    {
        $produced = 0;
        $stream = (static function () use (&$produced): Generator {
            for ($i = 0; $i < 50; $i++) {
                $produced++;
                yield ['n' => $i];
            }
        })();

        $rows = $this->flatten($stream, ['tail' => true]);

        self::assertCount(51, $rows);
        self::assertSame(50, $produced);
    }
}
