<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Handler;

use Doctrine\ORM\EntityManagerInterface;
use Sulu\Content\Application\ContentWorkflow\ContentWorkflowInterface;

/**
 * Publish and unpublish for content entities of Sulu 3 that are handled by a repository with findByUuid() and the
 * content workflow, without their own messages (the bundles Testimonials, Events, ...).
 *
 * Delete is not offered: those bundles do more than removing the row (trash, dependent data, domain events).
 */
class ContentEntityBulkActionHandler implements BulkActionHandlerInterface
{
    private const TRANSITIONS = ['publish' => 'publish', 'unpublish' => 'unpublish'];

    /**
     * @param class-string $entityClass
     */
    public function __construct(
        private readonly string $resourceKey,
        private readonly string $entityClass,
        private readonly string $securityContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly ContentWorkflowInterface $contentWorkflow,
    ) {
    }

    public function supports(string $resourceKey, string $action): bool
    {
        return $resourceKey === $this->resourceKey && isset(self::TRANSITIONS[$action]);
    }

    public function getSecurityContext(string $id, string $locale): ?string
    {
        return $this->securityContext;
    }

    public function getListSecurityContext(string $viewName): ?string
    {
        return $this->securityContext;
    }

    public function handle(string $action, array $ids, string $locale): array
    {
        $repository = $this->entityManager->getRepository($this->entityClass);
        $done = [];
        $failed = [];

        foreach ($ids as $id) {
            try {
                $entity = $repository->findByUuid($id);
                if (null === $entity) {
                    throw new \RuntimeException('Entry not found.');
                }

                $this->contentWorkflow->apply($entity, ['locale' => $locale], self::TRANSITIONS[$action]);
                $this->entityManager->flush();
                $done[] = $id;
            } catch (\Throwable $exception) {
                $failed[$id] = $exception->getMessage();
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }
}
