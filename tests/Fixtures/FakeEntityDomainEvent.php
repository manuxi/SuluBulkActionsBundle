<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Tests\Fixtures;

use Sulu\Bundle\ActivityBundle\Domain\Event\DomainEvent;

/**
 * Domain event constructed with (entity, payload), like PublishedEvent of the Events bundle.
 */
class FakeEntityDomainEvent extends DomainEvent
{
    public function __construct(public readonly object $entity, public readonly array $payload)
    {
        parent::__construct();
    }

    public function getEventType(): string
    {
        return 'fake';
    }

    public function getResourceKey(): string
    {
        return 'items';
    }

    public function getResourceId(): string
    {
        return 'entity';
    }
}
