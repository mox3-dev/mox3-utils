<?php

namespace Mox3\Utils\DbSync;

final class MysqlOptionFile
{
    public static function render(string $host, int $port, string $username, string $password): string
    {
        return "[client]\n"
            ."host={$host}\n"
            ."port={$port}\n"
            ."user={$username}\n"
            .'password="'.$password."\"\n";
    }

    public static function write(string $host, int $port, string $username, string $password): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dbsync_');
        chmod($path, 0600);
        file_put_contents($path, self::render($host, $port, $username, $password));

        return $path;
    }
}
