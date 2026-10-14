<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Tests\Fixtures;

use Sulu\Bundle\ActivityBundle\Domain\Event\DomainEvent;

/**
 * Domain event constructed with (uuid, title), like RemovedEvent of the Events bundle.
 */
class FakeRemovedDomainEvent extends DomainEvent
{
    public function __construct(public readonly string $uuid, public readonly string $title)
    {
        parent::__construct();
    }

    public function getEventType(): string
    {
        return 'removed';
    }

    public function getResourceKey(): string
    {
        return 'items';
    }

    public function getResourceId(): string
    {
        return $this->uuid;
    }
}
