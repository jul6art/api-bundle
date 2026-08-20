<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Tests\Fixtures\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Jul6Art\CoreBundle\Entity\Traits\IdTrait;

/**
 * One entity covering every shape the filters have to deal with: a text column, a numeric one, a
 * date, a JSON array, a nullable field, and an association one hop away.
 */
#[ORM\Entity]
#[ORM\Table(name: 'widget')]
class Widget
{
    use IdTrait;

    #[ORM\Column(length: 120)]
    private string $name;

    /** Numeric on purpose: a global search has to cast it before comparing. */
    #[ORM\Column]
    private int $reference = 0;

    #[ORM\Column(nullable: true)]
    private ?string $status = null;

    #[ORM\Column]
    private \DateTimeImmutable $issuedAt;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\ManyToOne(targetEntity: Category::class)]
    private ?Category $category = null;

    public function __construct(string $name = 'Widget')
    {
        $this->name = $name;
        $this->issuedAt = new \DateTimeImmutable();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getReference(): int
    {
        return $this->reference;
    }

    public function setReference(int $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getIssuedAt(): \DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function setIssuedAt(\DateTimeImmutable $issuedAt): static
    {
        $this->issuedAt = $issuedAt;

        return $this;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return $this->roles;
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;

        return $this;
    }
}
