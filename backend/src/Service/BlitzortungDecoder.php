<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Decodifica lo stream WebSocket di Blitzortung.org.
 *
 * I frame in arrivo NON sono JSON in chiaro: sono compressi con una variante
 * di LZW (dizionario che parte da 256). Questa e' la stessa routine usata dal
 * loro frontend, portata fedelmente in PHP.
 *
 * Nota: l'algoritmo lavora sui "caratteri" Unicode (code point), quindi usiamo
 * le funzioni mb_* con encoding UTF-8. I riferimenti a dizionario oltre 255
 * restano nel piano BMP per i payload di un singolo fulmine, quindi il
 * mapping code-point == code-unit JS tiene.
 */
final class BlitzortungDecoder
{
    /**
     * Decomprime un frame e ritorna la stringa JSON originale.
     */
    public function decompress(string $data): string
    {
        $chars = mb_str_split($data, 1, 'UTF-8');
        $n = \count($chars);
        if ($n === 0) {
            return '';
        }

        $dict = [];
        $currChar = $chars[0];
        $oldPhrase = $currChar;
        $out = [$currChar];
        $code = 256;

        for ($i = 1; $i < $n; $i++) {
            $cp = mb_ord($chars[$i], 'UTF-8');

            if ($cp < 256) {
                $phrase = $chars[$i];
            } else {
                $phrase = $dict[$cp] ?? ($oldPhrase . $currChar);
            }

            $out[] = $phrase;
            $currChar = mb_substr($phrase, 0, 1, 'UTF-8');
            $dict[$code] = $oldPhrase . $currChar;
            $code++;
            $oldPhrase = $phrase;
        }

        return implode('', $out);
    }

    /**
     * Decomprime e fa il parse del JSON. Ritorna null se il frame non e'
     * decodificabile (capita: keepalive, formati inattesi, ecc.).
     *
     * @return array<string,mixed>|null
     */
    public function decode(string $data): ?array
    {
        $json = $this->decompress($data);
        if ($json === '') {
            return null;
        }

        try {
            /** @var array<string,mixed>|null $decoded */
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        // Un fulmine valido ha almeno lat/lon e il timestamp in nanosecondi.
        if (!\is_array($decoded) || !isset($decoded['lat'], $decoded['lon'], $decoded['time'])) {
            return null;
        }

        return $decoded;
    }
}
