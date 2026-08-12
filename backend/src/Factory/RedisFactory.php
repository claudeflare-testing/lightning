<?php

declare(strict_types=1);

namespace App\Factory;

/**
 * Costruisce un client phpredis a partire da un DSN redis://host:port[/db].
 */
final class RedisFactory
{
    public static function create(string $dsn): \Redis
    {
        $parts = parse_url($dsn);
        $host = $parts['host'] ?? '127.0.0.1';
        $port = $parts['port'] ?? 6379;

        $redis = new \Redis();
        $redis->connect($host, (int) $port);

        if (!empty($parts['pass'])) {
            $redis->auth($parts['pass']);
        }
        if (isset($parts['path']) && $parts['path'] !== '/') {
            $redis->select((int) ltrim($parts['path'], '/'));
        }

        return $redis;
    }
}
