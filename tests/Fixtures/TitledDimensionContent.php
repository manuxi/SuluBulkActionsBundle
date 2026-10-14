<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Tests\Fixtures;

use Sulu\Content\Domain\Model\DimensionContentInterface;

/**
 * A dimension content with a title, like the ones of Testimonials and Events.
 */
interface TitledDimensionContent extends DimensionContentInterface
{
    public function getTitle(): ?string;
}
