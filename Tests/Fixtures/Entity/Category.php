<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Jul6Art\CoreBundle\Entity\Traits\IdTrait;

#[ORM\Entity]
#[ORM\Table(name: 'category')]
class Category
{
    use IdTrait;

    #[ORM\Column(length: 120)]
    private string $label;

    public function __construct(string $label = 'Catégorie')
    {
        $this->label = $label;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }
}
