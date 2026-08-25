<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * An EMBEDDABLE, not an entity — the shape that broke `OrSearchFilter`.
 *
 * Doctrine maps its fields into the holder's own table and addresses them as `w.address.city` in
 * DQL: a dotted path that looks exactly like a relation and must NOT be joined.
 */
#[ORM\Embeddable]
class Address
{
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $city = null;

    /** Numeric on purpose: an embedded number still has to be cast before a LIKE. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $floor = null;

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getFloor(): ?int
    {
        return $this->floor;
    }

    public function setFloor(?int $floor): static
    {
        $this->floor = $floor;

        return $this;
    }
}
