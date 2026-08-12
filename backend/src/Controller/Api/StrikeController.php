<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\LiveBuffer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API pubblica MINIMALE per la SPA React.
 *
 * IMPORTANTE: qui esponiamo solo cio' che serve a mostrare la mappa ai NOSTRI
 * utenti (bootstrap degli ultimi minuti + coordinate per iscriversi a Mercure).
 * NON e' un feed dati riutilizzabile da terzi: niente export storico pubblico,
 * niente download dell'archivio. Quello sta dietro login.
 */
#[Route('/api')]
final class StrikeController extends AbstractController
{
    public function __construct(private readonly LiveBuffer $buffer)
    {
    }

    /**
     * Bootstrap: i fulmini degli ultimi minuti, cosi' la mappa non parte vuota.
     */
    #[Route('/strikes/recent', name: 'api_strikes_recent', methods: ['GET'])]
    public function recent(Request $request): JsonResponse
    {
        $seconds = min(max((int) $request->query->get('seconds', 900), 60), 900);
        $strikes = $this->buffer->recent($seconds);

        return $this->json([
            'attribution' => 'Lightning data by Blitzortung.org and contributors',
            'window_seconds' => $seconds,
            'count' => \count($strikes),
            'strikes' => $strikes,
        ]);
    }

    /**
     * Config per il frontend: URL dell'hub Mercure e topic della zona.
     * Il cookie di autorizzazione Mercure viene settato qui (topic pubblici di
     * sola lettura per la mappa live).
     */
    #[Route('/realtime/config', name: 'api_realtime_config', methods: ['GET'])]
    public function realtimeConfig(Request $request, Authorization $authorization): JsonResponse
    {
        // Topic per zona: il client puo' iscriversi solo alle proprie celle.
        $topics = ['lightning/{geohash}'];

        // Setta il cookie JWT di sottoscrizione (lettura) per i topic della mappa.
        $authorization->setCookie($request, $topics);

        return $this->json([
            'hub' => $_ENV['MERCURE_PUBLIC_URL'] ?? '/.well-known/mercure',
            'topics' => $topics,
        ]);
    }
}
