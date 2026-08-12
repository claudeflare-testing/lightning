<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Strike;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Consumer dell'archivio: scarica la coda Redis riempita dal worker e la
 * persiste su Postgres in batch.
 *
 * Perche' separato dal worker: Doctrine + un loop ReactPHP che gira per giorni
 * = memory leak e connessioni stantie. Tenere la scrittura DB in un processo a
 * se', con clear() periodico, e' il pattern corretto. Gira anch'esso come
 * servizio systemd.
 */
#[AsCommand(
    name: 'app:archive:consume',
    description: 'Persiste i fulmini dalla coda Redis su Postgres',
)]
final class ArchiveConsumeCommand extends Command
{
    private const QUEUE = 'strikes:archive_queue';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly \Redis $redis,
        private readonly int $batchSize = 200,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Archive consumer avviato</info>');
        $count = 0;

        while (true) {
            // BLPOP: blocca fino a 5s in attesa di elementi (no busy loop).
            $item = $this->redis->blPop([self::QUEUE], 5);
            if (empty($item)) {
                continue;
            }

            $data = json_decode($item[1], true);
            if (!\is_array($data)) {
                continue;
            }

            $strike = (new Strike())
                ->setOccurredAt((new \DateTimeImmutable())->setTimestamp(intdiv((int) $data['ts'], 1000)))
                ->setLat((float) $data['lat'])
                ->setLon((float) $data['lon'])
                ->setAltitude($data['alt'] ?? null)
                ->setStationCount($data['sig'] ?? null)
                ->setGeohash($data['geohash'] ?? null);

            $this->em->persist($strike);
            $count++;

            if ($count % $this->batchSize === 0) {
                $this->em->flush();
                $this->em->clear(); // evita crescita illimitata dell'unit of work
                $output->writeln("<comment>Flush: {$count} fulmini archiviati</comment>");
            }
        }
    }
}
