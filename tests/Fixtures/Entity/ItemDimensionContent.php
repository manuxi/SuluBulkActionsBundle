<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'test_item_dimension_contents')]
class ItemDimensionContent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Item::class, inversedBy: 'dimensionContents')]
        #[ORM\JoinColumn(name: 'itemUuid', referencedColumnName: 'uuid', nullable: false)]
        public Item $item,
        #[ORM\Column(type: 'string', length: 15, nullable: true)]
        public ?string $locale,
        #[ORM\Column(type: 'string', length: 15)]
        public string $stage,
        #[ORM\Column(type: 'string', length: 191, nullable: true)]
        public ?string $title,
        #[ORM\Column(type: 'integer')]
        public int $version,
    ) {
    }
}
