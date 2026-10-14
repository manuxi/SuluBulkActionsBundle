<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Handler;

use Doctrine\ORM\EntityManagerInterface;
use Sulu\Bundle\ActivityBundle\Application\Collector\DomainEventCollectorInterface;
use Sulu\Bundle\ActivityBundle\Domain\Event\DomainEvent;
use Sulu\Bundle\TrashBundle\Application\TrashManager\TrashManagerInterface;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Application\ContentWorkflow\ContentWorkflowInterface;
use Sulu\Content\Domain\Model\ContentRichEntityInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;

/**
 * Publish, unpublish, delete and copy locale for content entities of Sulu 3 that are handled by a repository with
 * findByUuid(), the content manager and the content workflow, without messages of their own (the bundles
 * Testimonials, Events, ...).
 *
 * It does what the controllers of those bundles do: delete stores the entity in the trash first, and the domain
 * events (published, unpublished, removed) are collected so that the activity log and the search index of the bundle
 * stay up to date. Domain events are given as classes by type:
 *
 * - published, unpublished: constructed with (entity, payload)
 * - removed: constructed with (uuid, title)
 */
class ContentEntityBulkActionHandler implements BulkActionHandlerInterface, LocaleCopyHandlerInterface
{
    public const EVENT_PUBLISHED = 'published';
    public const EVENT_UNPUBLISHED = 'unpublished';
    public const EVENT_REMOVED = 'removed';

    private const TRANSITIONS = ['publish' => 'publish', 'unpublish' => 'unpublish'];
    private const ACTIONS = ['publish', 'unpublish', 'delete', LocaleCopyHandlerInterface::ACTION];

    private readonly DimensionContentLookup $lookup;

    /**
     * @param class-string $entityClass
     * @param array<string, class-string<DomainEvent>> $domainEvents the domain event class by type (see the constants)
     */
    public function __construct(
        private readonly string $resourceKey,
        private readonly string $entityClass,
        private readonly string $securityContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly ContentWorkflowInterface $contentWorkflow,
        private readonly ContentManagerInterface $contentManager,
        private readonly ?DomainEventCollectorInterface $domainEventCollector = null,
        private readonly ?TrashManagerInterface $trashManager = null,
        private readonly array $domainEvents = [],
    ) {
        $this->lookup = new DimensionContentLookup($entityManager, $entityClass);
    }

    public function supports(string $resourceKey, string $action): bool
    {
        return $resourceKey === $this->resourceKey && \in_array($action, self::ACTIONS, true);
    }

    public function getSecurityContext(string $id, string $locale): ?string
    {
        return $this->securityContext;
    }

    public function getListSecurityContext(string $viewName): ?string
    {
        return $this->securityContext;
    }

    public function findMissingInLocale(array $ids, string $locale): array
    {
        return $this->lookup->findMissingInLocale($ids, $locale);
    }

    public function getTitles(array $ids, string $locale): array
    {
        return $this->lookup->getTitles($ids, $locale);
    }

    public function handle(string $action, array $ids, string $locale): array
    {
        if (!isset(self::TRANSITIONS[$action]) && 'delete' !== $action) {
            throw new \InvalidArgumentException(\sprintf('Unknown action "%s" ("copy_locale" goes through copyLocale()).', $action));
        }

        return $this->each($ids, function (ContentRichEntityInterface $entity, string $id) use ($action, $locale): void {
            if ('delete' === $action) {
                $this->remove($entity, $id, $locale);
            } else {
                $this->transition($entity, $action, $locale);
            }
        });
    }

    public function copyLocale(array $ids, string $sourceLocale, string $targetLocale): array
    {
        return $this->each($ids, function (ContentRichEntityInterface $entity) use ($sourceLocale, $targetLocale): void {
            $this->contentManager->copy(
                $entity,
                ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => $sourceLocale],
                $entity,
                ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => $targetLocale],
            );

            $this->entityManager->flush();
        });
    }

    /**
     * One entity after the other, each flushed on its own, so one failing entity does not undo the ones before it.
     *
     * @param list<string> $ids
     * @param callable(ContentRichEntityInterface, string): void $callback
     *
     * @return array{done: list<string>, failed: array<string, string>}
     */
    private function each(array $ids, callable $callback): array
    {
        $repository = $this->entityManager->getRepository($this->entityClass);
        $done = [];
        $failed = [];

        foreach ($ids as $id) {
            try {
                $entity = $repository->findByUuid($id);
                if (!$entity instanceof ContentRichEntityInterface) {
                    throw new \RuntimeException('Entry not found.');
                }

                $callback($entity, $id);
                $done[] = $id;
            } catch (\Throwable $exception) {
                // events collected for the failed entity must not be dispatched with the next flush
                $this->domainEventCollector?->clear();
                $failed[$id] = $exception->getMessage();
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }

    private function transition(ContentRichEntityInterface $entity, string $action, string $locale): void
    {
        $dimensionAttributes = ['locale' => $locale, 'stage' => DimensionContentInterface::STAGE_DRAFT];

        $this->contentWorkflow->apply($entity, ['locale' => $locale], self::TRANSITIONS[$action]);
        $dimensionContent = $this->contentManager->resolve($entity, $dimensionAttributes);

        $this->collect(
            'publish' === $action ? self::EVENT_PUBLISHED : self::EVENT_UNPUBLISHED,
            $entity,
            ['locale' => $locale, 'title' => $this->titleOf($dimensionContent)],
        );
        $this->entityManager->flush();
    }

    private function remove(ContentRichEntityInterface $entity, string $id, string $locale): void
    {
        $title = '';
        foreach ($entity->getDimensionContents() as $dimensionContent) {
            if ($dimensionContent->getLocale() === $locale && '' !== $this->titleOf($dimensionContent)) {
                $title = $this->titleOf($dimensionContent);
                break;
            }
        }

        $this->trashManager?->store($this->resourceKey, $entity);
        $this->entityManager->remove($entity);

        $class = $this->domainEvents[self::EVENT_REMOVED] ?? null;
        if (null !== $class && null !== $this->domainEventCollector) {
            $this->domainEventCollector->collect(new $class($id, $title));
        }

        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function collect(string $type, ContentRichEntityInterface $entity, array $payload): void
    {
        $class = $this->domainEvents[$type] ?? null;
        if (null === $class || null === $this->domainEventCollector) {
            return;
        }

        $this->domainEventCollector->collect(new $class($entity, $payload));
    }

    private function titleOf(DimensionContentInterface $dimensionContent): string
    {
        return method_exists($dimensionContent, 'getTitle') ? (string) $dimensionContent->getTitle() : '';
    }
}
