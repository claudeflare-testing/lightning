<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Buffer live dei fulmini in Redis.
 *
 * Perche' Redis e non Postgres: worker e web server sono processi separati e
 * non condividono la RAM. Il worker scrive qui gli ultimi minuti; il
 * controller li rilegge al primo caricamento della pagina. Finestra scorrevole
 * con scadenza automatica: niente pulizia manuale.
 *
 * Struttura dati: un sorted set "strikes:recent" con score = timestamp (ms) e
 * member = JSON compatto del fulmine. Cosi' "dammi tutto dalle 14:30" e' O(logN).
 */
final class LiveBuffer
{
    private const KEY = 'strikes:recent';

    public function __construct(
        private readonly \Redis $redis,
        private readonly int $windowSeconds = 900, // 15 minuti
    ) {
    }

    /**
     * Aggiunge un fulmine al buffer e pota quelli piu' vecchi della finestra.
     *
     * @param array<string,mixed> $strike gia' normalizzato (vedi normalizeStrike nel worker)
     */
    public function push(array $strike): void
    {
        $tsMs = (int) $strike['ts']; // millisecondi
        $payload = json_encode($strike, \JSON_THROW_ON_ERROR);

        // member univoco: prefisso timestamp per evitare collisioni di ZADD.
        $member = $tsMs . ':' . substr(md5($payload), 0, 8);

        $this->redis->zAdd(self::KEY, $tsMs, $member . '|' . $payload);

        // Potatura probabilistica per non farlo a ogni fulmine (sarebbero troppe call).
        if (random_int(1, 50) === 1) {
            $this->trim();
        }

        // Se il set resta vuoto a lungo, non deve sopravvivere all'infinito.
        $this->redis->expire(self::KEY, $this->windowSeconds * 2);
    }

    /**
     * Ritorna i fulmini degli ultimi $seconds secondi (default: intera finestra).
     *
     * @return list<array<string,mixed>>
     */
    public function recent(?int $seconds = null, int $nowMs = 0): array
    {
        $seconds ??= $this->windowSeconds;
        $now = $nowMs > 0 ? $nowMs : (int) (microtime(true) * 1000);
        $min = $now - $seconds * 1000;

        $rows = $this->redis->zRangeByScore(self::KEY, (string) $min, '+inf');

        $out = [];
        foreach ($rows as $row) {
            $sep = strpos($row, '|');
            if ($sep === false) {
                continue;
            }
            $decoded = json_decode(substr($row, $sep + 1), true);
            if (\is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    public function trim(int $nowMs = 0): void
    {
        $now = $nowMs > 0 ? $nowMs : (int) (microtime(true) * 1000);
        $cutoff = $now - $this->windowSeconds * 1000;
        $this->redis->zRemRangeByScore(self::KEY, '-inf', (string) $cutoff);
    }
}
