<?php

namespace Mox3\Utils\Tests\Unit;

use Mox3\Utils\DbSync\MysqlOptionFile;
use PHPUnit\Framework\TestCase;

class MysqlOptionFileTest extends TestCase
{
    public function test_render_produces_a_client_section(): void
    {
        $out = MysqlOptionFile::render('127.0.0.1', 3306, 'root', 's3cr3t');

        $this->assertStringContainsString("[client]\n", $out);
        $this->assertStringContainsString("host=\"127.0.0.1\"\n", $out);
        $this->assertStringContainsString("port=3306\n", $out);
        $this->assertStringContainsString("user=\"root\"\n", $out);
        $this->assertStringContainsString('password="s3cr3t"', $out);
    }

    public function test_render_escapes_quotes_backslashes_and_newlines_in_values(): void
    {
        $out = MysqlOptionFile::render('h', 3306, 'us"er', "pa\"ss\\wo\nrd");

        $this->assertStringContainsString('user="us\"er"', $out);
        $this->assertStringContainsString('password="pa\"ss\\\\wo\nrd"', $out);
    }

    public function test_write_creates_a_0600_file_with_the_rendered_contents(): void
    {
        $path = MysqlOptionFile::write('h', 3306, 'u', 'p');

        try {
            $this->assertFileExists($path);
            $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
            $this->assertSame(MysqlOptionFile::render('h', 3306, 'u', 'p'), file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }
}
