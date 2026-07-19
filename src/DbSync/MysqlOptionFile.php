<?php

namespace Mox3\Utils\DbSync;

final class MysqlOptionFile
{
    public static function render(string $host, int $port, string $username, string $password): string
    {
        return "[client]\n"
            .'host="'.self::escape($host)."\"\n"
            ."port={$port}\n"
            .'user="'.self::escape($username)."\"\n"
            .'password="'.self::escape($password)."\"\n";
    }

    public static function write(string $host, int $port, string $username, string $password): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dbsync_');
        if ($path === false) {
            throw new \RuntimeException('Could not create a temporary MySQL option file.');
        }

        if (chmod($path, 0600) === false) {
            @unlink($path);
            throw new \RuntimeException('Could not secure the MySQL option file (chmod 0600 failed).');
        }

        if (file_put_contents($path, self::render($host, $port, $username, $password)) === false) {
            @unlink($path);
            throw new \RuntimeException('Could not write the MySQL option file.');
        }

        return $path;
    }

    private static function escape(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace('"', '\\"', $value);
        $value = str_replace(["\n", "\r", "\t"], ['\\n', '\\r', '\\t'], $value);

        return $value;
    }
}
