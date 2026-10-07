<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Tests\Fixtures\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A content entity like the ones of Sulu 3: the UUID as identifier, the content in dimension contents.
 */
#[ORM\Entity]
#[ORM\Table(name: 'test_items')]
class Item
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    public string $uuid;

    /** @var Collection<int, ItemDimensionContent> */
    #[ORM\OneToMany(targetEntity: ItemDimensionContent::class, mappedBy: 'item', cascade: ['persist'])]
    public Collection $dimensionContents;

    public function __construct(string $uuid)
    {
        $this->uuid = $uuid;
        $this->dimensionContents = new ArrayCollection();
    }

    public function addDimension(?string $locale, string $stage, ?string $title = null, int $version = 0): self
    {
        $this->dimensionContents->add(new ItemDimensionContent($this, $locale, $stage, $title, $version));

        return $this;
    }
}
