<?php

namespace App\Enums;

enum SourceType: string
{
    case PostgreSQL = 'postgresql';
    case MySQL = 'mysql';
    case MariaDB = 'mariadb';
    case MongoDB = 'mongodb';
    case SQLite = 'sqlite';
    case Redis = 'redis';
    case Directory = 'directory';
    case DockerVolume = 'docker_volume';

    public function isDatabase(): bool
    {
        return in_array($this, [
            self::PostgreSQL,
            self::MySQL,
            self::MariaDB,
            self::MongoDB,
            self::SQLite,
            self::Redis,
        ]);
    }

    public function defaultPort(): ?int
    {
        return match ($this) {
            self::PostgreSQL => 5432,
            self::MySQL, self::MariaDB => 3306,
            self::MongoDB => 27017,
            self::Redis => 6379,
            default => null,
        };
    }
}
