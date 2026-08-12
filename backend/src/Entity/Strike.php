<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StrikeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Archivio permanente e PRIVATO dei fulmini.
 *
 * Questa tabella non va mai esposta pubblicamente come feed/dataset: sta dietro
 * autenticazione (Symfony Security). Serve a te e al tuo socio per storico,
 * statistiche e mappe di densita', in linea con l'accesso da partecipante.
 */
#[ORM\Entity(repositoryClass: StrikeRepository::class)]
#[ORM\Table(name: 'strike')]
#[ORM\Index(name: 'idx_strike_occurred', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_strike_geohash', columns: ['geohash'])]
class Strike
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: 'float')]
    private float $lat;

    #[ORM\Column(type: 'float')]
    private float $lon;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $altitude = null;

    /** Numero di stazioni che hanno rilevato la scarica (indice di affidabilita'). */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $stationCount = null;

    /** Geohash a precisione ridotta: comodo per query per zona e clustering. */
    #[ORM\Column(type: 'string', length: 12, nullable: true)]
    private ?string $geohash = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(\DateTimeImmutable $occurredAt): self
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }

    public function getLat(): float
    {
        return $this->lat;
    }

    public function setLat(float $lat): self
    {
        $this->lat = $lat;

        return $this;
    }

    public function getLon(): float
    {
        return $this->lon;
    }

    public function setLon(float $lon): self
    {
        $this->lon = $lon;

        return $this;
    }

    public function getAltitude(): ?int
    {
        return $this->altitude;
    }

    public function setAltitude(?int $altitude): self
    {
        $this->altitude = $altitude;

        return $this;
    }

    public function getStationCount(): ?int
    {
        return $this->stationCount;
    }

    public function setStationCount(?int $stationCount): self
    {
        $this->stationCount = $stationCount;

        return $this;
    }

    public function getGeohash(): ?string
    {
        return $this->geohash;
    }

    public function setGeohash(?string $geohash): self
    {
        $this->geohash = $geohash;

        return $this;
    }
}
