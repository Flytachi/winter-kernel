<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Integration\Fixtures;

/**
 * Typed entity used by the Types integration tests.
 *
 * Property types deliberately exercise the framework's hydration logic:
 *   - `?int`, `?bool` — assigned from PDO string returns; PHP's typed-property
 *     coercion turns '42'→42, '1'→true, '0'→false on assignment.
 *   - `?string` for decimal/datetime/json/uuid/text — strings stay as-is, so
 *     value precision (NUMERIC scale, JSON structure, UUID case) is preserved
 *     and we don't lose data through float conversion.
 */
final class SpecimenEntity
{
    public int $id;
    public ?string $str_col = null;
    public ?int $int_col = null;
    public ?bool $bool_col = null;
    public ?string $dec_col = null;
    public ?string $ts_col = null;
    public ?string $json_col = null;
    public ?string $uuid_col = null;
    public ?string $text_col = null;
}
