<?php

namespace Mox3\Utils\Tests\Unit;

use InvalidArgumentException;
use Mox3\Utils\DbSync\DatabaseTargets;
use PHPUnit\Framework\TestCase;

class DatabaseTargetsTest extends TestCase
{
    public function test_no_entries_returns_empty_list(): void
    {
        $this->assertSame([], DatabaseTargets::parse([]));
        $this->assertSame([], DatabaseTargets::parse(['', '   ']));
    }

    public function test_bare_name_targets_the_same_name(): void
    {
        $this->assertSame([['app', 'app']], DatabaseTargets::parse(['app']));
    }

    public function test_repeatable_entries_preserve_order(): void
    {
        $this->assertSame(
            [['app', 'app'], ['billing', 'billing']],
            DatabaseTargets::parse(['app', 'billing'])
        );
    }

    public function test_comma_separated_entries_are_split(): void
    {
        $this->assertSame(
            [['app', 'app'], ['billing', 'billing']],
            DatabaseTargets::parse(['app, billing'])
        );
    }

    public function test_source_target_mapping_renames_the_target(): void
    {
        $this->assertSame(
            [['prod_app', 'local_app']],
            DatabaseTargets::parse(['prod_app:local_app'])
        );
    }

    public function test_empty_target_falls_back_to_source_name(): void
    {
        $this->assertSame([['app', 'app']], DatabaseTargets::parse(['app:']));
    }

    public function test_mixes_bare_mapped_and_comma_forms_with_trimming(): void
    {
        $this->assertSame(
            [['a', 'a'], ['b', 'c'], ['d', 'd']],
            DatabaseTargets::parse([' a , b:c ', 'd'])
        );
    }

    public function test_blank_entries_are_skipped(): void
    {
        $this->assertSame([['x', 'x']], DatabaseTargets::parse(['', '  ', 'x']));
    }

    public function test_empty_source_name_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DatabaseTargets::parse([':local']);
    }
}
