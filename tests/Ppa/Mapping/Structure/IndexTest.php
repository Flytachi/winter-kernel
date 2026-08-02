<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Ppa\Mapping\Structure;

use Flytachi\Winter\Kernel\Ppa\Mapping\Constants\IndexMethod;
use Flytachi\Winter\Kernel\Ppa\Mapping\Constants\IndexType;
use Flytachi\Winter\Kernel\Ppa\Mapping\Structure\Index;
use Flytachi\Winter\Kernel\Ppa\Mapping\Structure\StructureInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IndexTest extends TestCase
{
    public function test_implements_structure_interface(): void
    {
        self::assertInstanceOf(StructureInterface::class, new Index(columns: ['id']));
    }

    public function test_defaults_are_index_btree(): void
    {
        $idx = new Index(columns: ['id']);
        self::assertSame(IndexType::INDEX, $idx->type);
        self::assertSame(IndexMethod::BTREE, $idx->method);
        self::assertNull($idx->where);
        self::assertSame([], $idx->includeColumns);
        self::assertNull($idx->opClass);
    }

    public function test_invalid_explicit_name_throws_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Index(columns: ['id'], name: 'bad-name');
    }

    // ── mysql ────────────────────────────────────────────────────────────────

    public function test_mysql_simple_index_btree_omits_using_clause(): void
    {
        $sql = (new Index(columns: ['email']))->toSql('users', 'mysql');
        self::assertSame('CREATE INDEX users_email_idx ON users (email)', $sql);
    }

    public function test_mysql_unique_index(): void
    {
        $sql = (new Index(columns: ['email'], type: IndexType::UNIQUE))->toSql('users', 'mysql');
        self::assertSame('CREATE UNIQUE INDEX users_email_udx ON users (email)', $sql);
    }

    public function test_mysql_primary_key(): void
    {
        $sql = (new Index(columns: ['id'], type: IndexType::PRIMARY))->toSql('users', 'mysql');
        self::assertSame('PRIMARY KEY (id)', $sql);
    }

    public function test_mysql_using_method_emitted_when_non_btree(): void
    {
        $sql = (new Index(columns: ['email'], method: IndexMethod::HASH))->toSql('users', 'mysql');
        self::assertSame('CREATE INDEX users_email_idx ON users USING HASH (email)', $sql);
    }

    // ── pgsql ────────────────────────────────────────────────────────────────

    public function test_pgsql_simple_index_always_emits_using_clause(): void
    {
        $sql = (new Index(columns: ['email']))->toSql('users', 'pgsql');
        self::assertSame('CREATE INDEX users_email_idx ON users USING BTREE (email)', $sql);
    }

    public function test_pgsql_unique_index(): void
    {
        $sql = (new Index(columns: ['email'], type: IndexType::UNIQUE))->toSql('users', 'pgsql');
        self::assertSame('CREATE UNIQUE INDEX users_email_udx ON users USING BTREE (email)', $sql);
    }

    public function test_pgsql_primary_key(): void
    {
        $sql = (new Index(columns: ['id'], type: IndexType::PRIMARY))->toSql('users', 'pgsql');
        self::assertSame('PRIMARY KEY (id)', $sql);
    }

    public function test_pgsql_composite_primary_key_format(): void
    {
        $sql = (new Index(columns: ['user_id', 'role_id'], type: IndexType::PRIMARY))->toSql('user_role', 'pgsql');
        self::assertSame('PRIMARY KEY (user_id, role_id)', $sql);
    }

    public function test_pgsql_where_partial_index(): void
    {
        $sql = (new Index(columns: ['email'], where: 'active = true'))->toSql('users', 'pgsql');
        self::assertSame(
            'CREATE INDEX users_email_idx ON users USING BTREE (email) WHERE active = true',
            $sql,
        );
    }

    public function test_pgsql_include_columns(): void
    {
        $sql = (new Index(columns: ['user_id'], includeColumns: ['email', 'name']))
            ->toSql('users', 'pgsql');
        self::assertSame(
            'CREATE INDEX users_user_id_idx ON users USING BTREE (user_id) INCLUDE (email, name)',
            $sql,
        );
    }

    public function test_pgsql_opclass_appends_to_first_column(): void
    {
        $sql = (new Index(columns: ['name'], method: IndexMethod::GIN, opClass: 'gin_trgm_ops'))
            ->toSql('users', 'pgsql');
        self::assertSame(
            'CREATE INDEX users_name_idx ON users USING GIN (name gin_trgm_ops)',
            $sql,
        );
    }

    public function test_pgsql_where_and_include_combined(): void
    {
        $sql = (new Index(
            columns: ['user_id'],
            where: 'deleted_at IS NULL',
            includeColumns: ['email'],
        ))->toSql('users', 'pgsql');

        self::assertSame(
            'CREATE INDEX users_user_id_idx ON users USING BTREE (user_id)'
            . ' INCLUDE (email) WHERE deleted_at IS NULL',
            $sql,
        );
    }

    // ── Multi-column / schema-qualified ──────────────────────────────────────

    public function test_multi_column_index_concatenates_columns_in_name(): void
    {
        $sql = (new Index(columns: ['name', 'email']))->toSql('users', 'pgsql');
        self::assertStringContainsString('users_name_email_idx', $sql);
        self::assertStringContainsString(' (name, email)', $sql);
    }

    public function test_schema_qualified_table_uses_only_table_part_for_index_name(): void
    {
        $sql = (new Index(columns: ['email']))->toSql('public.users', 'pgsql');
        // basename of "public/users" → "users"
        self::assertStringContainsString('users_email_idx ON public.users', $sql);
    }

    // ── Explicit name overrides auto-generation ──────────────────────────────

    public function test_explicit_name_used_with_suffix(): void
    {
        $sql = (new Index(columns: ['email'], name: 'custom'))->toSql('users', 'pgsql');
        // explicit name still gets table-prefix and type-suffix
        self::assertStringContainsString('users_custom_idx', $sql);
    }

    // ── Method enum coverage ─────────────────────────────────────────────────

    /** @return array<string, array{0: IndexMethod}> */
    public static function pgsqlMethods(): array
    {
        return [
            'btree' => [IndexMethod::BTREE],
            'hash'  => [IndexMethod::HASH],
            'gist'  => [IndexMethod::GIST],
            'gin'   => [IndexMethod::GIN],
        ];
    }

    #[DataProvider('pgsqlMethods')]
    public function test_pgsql_emits_using_for_every_index_method(IndexMethod $m): void
    {
        $sql = (new Index(columns: ['c'], method: $m))->toSql('t', 'pgsql');
        self::assertStringContainsString(" USING {$m->value} ", $sql);
    }

    // ── Name truncation on long names ────────────────────────────────────────

    public function test_pgsql_long_name_is_truncated_and_hashed_within_limit(): void
    {
        $cols = ['col_a_extra_long', 'col_b_extra_long', 'col_c_extra_long'];
        $sql = (new Index(columns: $cols))->toSql('users_table_very_long_name', 'pgsql');

        // Truncated form: prefix + '_' + crc32b (8 hex chars).
        self::assertMatchesRegularExpression(
            '/CREATE INDEX ([a-z_]+_[0-9a-f]{8}) ON users_table_very_long_name/',
            $sql,
        );

        preg_match('/CREATE INDEX (\S+) ON /', $sql, $m);
        $indexName = $m[1];
        self::assertLessThanOrEqual(63, strlen($indexName), 'pgsql identifier limit is 63');

        // Un-truncated full name should not appear verbatim.
        self::assertStringNotContainsString(
            'users_table_very_long_name_col_a_extra_long_col_b_extra_long_col_c_extra_long_idx',
            $sql,
        );
    }

    public function test_mysql_long_name_is_truncated_within_64_char_limit(): void
    {
        $cols = ['col_a_extra_long', 'col_b_extra_long', 'col_c_extra_long'];
        $sql = (new Index(columns: $cols))->toSql('users_table_very_long_name', 'mysql');

        preg_match('/CREATE INDEX (\S+) ON /', $sql, $m);
        $indexName = $m[1];
        self::assertLessThanOrEqual(64, strlen($indexName), 'mysql identifier limit is 64');
    }

    public function test_pg_and_mysql_share_same_hash_suffix_on_long_names(): void
    {
        // Pre-truncation $nameSql is dialect-independent, so the crc32b hash is identical.
        $cols = ['col_a_extra_long', 'col_b_extra_long', 'col_c_extra_long'];
        $pg = (new Index(columns: $cols))->toSql('users_table_very_long_name', 'pgsql');
        $my = (new Index(columns: $cols))->toSql('users_table_very_long_name', 'mysql');

        preg_match('/_([0-9a-f]{8}) ON/', $pg, $pgM);
        preg_match('/_([0-9a-f]{8}) ON/', $my, $myM);
        self::assertNotEmpty($pgM);
        self::assertNotEmpty($myM);
        self::assertSame($pgM[1], $myM[1]);
    }

    // ── Unsupported dialect ──────────────────────────────────────────────────

    public function test_unsupported_dialect_throws(): void
    {
        // sqlite used to stand in for "unsupported" here; it is a supported dialect now,
        // so the guard is proven with one the mapper genuinely does not know.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported dialect: oci');
        (new Index(columns: ['id']))->toSql('users', 'oci');
    }

    // ── SQLite ───────────────────────────────────────────────────────────────

    public function test_sqlite_index_has_no_using_clause(): void
    {
        // SQLite has a single index implementation, so USING would be a syntax error.
        $sql = (new Index(columns: ['email'], type: IndexType::UNIQUE))->toSql('users', 'sqlite');

        self::assertStringContainsString('CREATE UNIQUE INDEX', $sql);
        self::assertStringContainsString('ON users (email)', $sql);
        self::assertStringNotContainsString('USING', $sql);
    }

    public function test_sqlite_primary_key_is_a_table_constraint(): void
    {
        $sql = (new Index(columns: ['id'], type: IndexType::PRIMARY))->toSql('users', 'sqlite');

        self::assertSame('PRIMARY KEY (id)', $sql);
    }

    public function test_sqlite_supports_a_partial_index(): void
    {
        $sql = (new Index(columns: ['email'], type: IndexType::INDEX, where: 'deleted_at IS NULL'))
            ->toSql('users', 'sqlite');

        self::assertStringContainsString('WHERE deleted_at IS NULL', $sql);
    }
}
