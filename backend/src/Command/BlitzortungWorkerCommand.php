<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\BlitzortungDecoder;
use App\Service\LiveBuffer;
use Psr\Log\LoggerInterface;
use Ratchet\Client\Connector as PawlConnector;
use Ratchet\Client\WebSocket;
use React\EventLoop\Loop;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Il "server in mezzo": UNA sola connessione a Blitzortung, fan-out verso i
 * nostri client via Mercure. Rispetta la loro policy (i browser NON si
 * collegano ai loro server) e non ridistribuisce i dati come dataset.
 *
 * Flusso: WSS -> decode LZW -> filtro per bounding box -> normalizza
 *   -> Redis LiveBuffer (per il primo caricamento)
 *   -> Mercure (real-time ai browser)
 *   -> coda Redis d'archivio (il consumer la scarica su Postgres)
 *
 * Va lanciato come servizio systemd (vedi deploy/blitzortung-worker.service),
 * NON come richiesta web.
 */
#[AsCommand(
    name: 'app:blitzortung:worker',
    description: 'Mantiene la connessione a Blitzortung e distribuisce i fulmini',
)]
final class BlitzortungWorkerCommand extends Command
{
    /** Host WebSocket noti: si prova il successivo se uno cade. */
    private const HOSTS = [
        'wss://ws1.blitzortung.org/',
        'wss://ws7.blitzortung.org/',
        'wss://ws8.blitzortung.org/',
    ];

    private const ARCHIVE_QUEUE = 'strikes:archive_queue';

    private int $hostIndex = 0;
    private float $reconnectDelay = 1.0;

    public function __construct(
        private readonly BlitzortungDecoder $decoder,
        private readonly LiveBuffer $buffer,
        private readonly HubInterface $hub,
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger,
        // Bounding box della zona di interesse (default: Italia).
        // Sovrascrivibili da env: BOX_MIN_LAT ecc.
        private readonly float $minLat = 35.0,
        private readonly float $maxLat = 47.5,
        private readonly float $minLon = 6.0,
        private readonly float $maxLon = 19.0,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Blitzortung worker avviato</info>');
        $this->connect($output);
        Loop::run();

        return Command::SUCCESS;
    }

    private function connect(OutputInterface $output): void
    {
        $host = self::HOSTS[$this->hostIndex % \count(self::HOSTS)];
        $connector = new PawlConnector();

        $connector($host)->then(
            function (WebSocket $conn) use ($output, $host): void {
                $this->reconnectDelay = 1.0; // reset backoff a connessione riuscita
                $output->writeln("<info>Connesso a {$host}</info>");

                // Messaggio di subscribe: avvia lo stream.
                $conn->send('{"a":111}');

                $conn->on('message', function ($msg) use ($output): void {
                    $this->onMessage((string) $msg, $output);
                });

                $conn->on('close', function ($code = null) use ($output): void {
                    $output->writeln("<comment>Connessione chiusa (code={$code}), riconnetto...</comment>");
                    $this->scheduleReconnect($output);
                });
            },
            function (\Throwable $e) use ($output): void {
                $this->logger->warning('WS connect fallita: '.$e->getMessage());
                $output->writeln("<error>Connessione fallita: {$e->getMessage()}</error>");
                $this->hostIndex++; // prova un altro host
                $this->scheduleReconnect($output);
            }
        );
    }

    private function scheduleReconnect(OutputInterface $output): void
    {
        $delay = $this->reconnectDelay;
        $this->reconnectDelay = min($this->reconnectDelay * 2, 30.0); // backoff esponenziale
        Loop::addTimer($delay, fn () => $this->connect($output));
    }

    private function onMessage(string $raw, OutputInterface $output): void
    {
        $data = $this->decoder->decode($raw);
        if ($data === null) {
            return; // keepalive o frame non-fulmine
        }

        $lat = (float) $data['lat'];
        $lon = (float) $data['lon'];

        // Filtro geografico: teniamo solo la nostra zona.
        if ($lat < $this->minLat || $lat > $this->maxLat || $lon < $this->minLon || $lon > $this->maxLon) {
            return;
        }

        $strike = $this->normalize($data);

        // 1) buffer live (primo caricamento pagina)
        try {
            $this->buffer->push($strike);
        } catch (\Throwable $e) {
            $this->logger->error('LiveBuffer: '.$e->getMessage());
        }

        // 2) real-time ai browser via Mercure (topic per zona geohash-2)
        try {
            $topic = 'lightning/'.substr($strike['geohash'] ?? '', 0, 2);
            $this->hub->publish(new Update($topic, json_encode($strike, \JSON_THROW_ON_ERROR)));
        } catch (\Throwable $e) {
            $this->logger->error('Mercure: '.$e->getMessage());
        }

        // 3) coda d'archivio: il consumer la scarica su Postgres in batch
        //    (NON usiamo Doctrine dentro il loop ReactPHP: si tiene fuori dal
        //    processo long-running).
        try {
            $this->redis->rPush(self::ARCHIVE_QUEUE, json_encode($strike, \JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            $this->logger->error('Archive queue: '.$e->getMessage());
        }
    }

    /**
     * Normalizza il payload grezzo di Blitzortung in una forma stabile per noi.
     * `time` arriva in NANOSECONDI dall'epoch.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalize(array $data): array
    {
        $tsMs = (int) ((int) $data['time'] / 1_000_000); // ns -> ms
        $lat = (float) $data['lat'];
        $lon = (float) $data['lon'];

        return [
            'ts' => $tsMs,
            'lat' => $lat,
            'lon' => $lon,
            'alt' => isset($data['alt']) ? (int) $data['alt'] : null,
            'sig' => isset($data['sig']) && \is_array($data['sig']) ? \count($data['sig']) : null,
            'geohash' => $this->geohash($lat, $lon, 6),
        ];
    }

    /** Geohash minimale (senza dipendenze esterne). */
    private function geohash(float $lat, float $lon, int $precision): string
    {
        $base32 = '0123456789bcdefghjkmnpqrstuvwxyz';
        $latRange = [-90.0, 90.0];
        $lonRange = [-180.0, 180.0];
        $hash = '';
        $bits = 0;
        $ch = 0;
        $even = true;

        while (\strlen($hash) < $precision) {
            if ($even) {
                $mid = ($lonRange[0] + $lonRange[1]) / 2;
                if ($lon >= $mid) { $ch |= (1 << (4 - $bits)); $lonRange[0] = $mid; }
                else { $lonRange[1] = $mid; }
            } else {
                $mid = ($latRange[0] + $latRange[1]) / 2;
                if ($lat >= $mid) { $ch |= (1 << (4 - $bits)); $latRange[0] = $mid; }
                else { $latRange[1] = $mid; }
            }
            $even = !$even;
            if ($bits < 4) {
                $bits++;
            } else {
                $hash .= $base32[$ch];
                $bits = 0;
                $ch = 0;
            }
        }

        return $hash;
    }
}
