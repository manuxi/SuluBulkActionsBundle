<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Handler;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Sulu\Page\Application\Message\ApplyWorkflowTransitionPageMessage;
use Sulu\Page\Application\Message\CopyLocalePageMessage;
use Sulu\Page\Application\Message\RemovePageMessage;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Infrastructure\Sulu\Admin\PageAdmin;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Pages: only "copy_locale" for now. The page tree of Sulu cannot select rows, so there is no list in the admin
 * where publish, unpublish or delete of pages could be offered and tested; a project with its own page list adds
 * it to "sulu_bulk_actions.resources.pages".
 *
 * Pages have one security context per webspace and can have permissions of their own (SecuredObjectHandlerInterface).
 */
class PageBulkActionHandler extends ContentWorkflowBulkActionHandler implements LocaleCopyHandlerInterface, SecuredObjectHandlerInterface
{
    private readonly DimensionContentLookup $lookup;

    public function __construct(
        MessageBusInterface $messageBus,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct($messageBus);
        $this->lookup = new DimensionContentLookup($entityManager, PageInterface::class);
    }

    protected function getResourceKey(): string
    {
        return PageInterface::RESOURCE_KEY;
    }

    public function supports(string $resourceKey, string $action): bool
    {
        return $resourceKey === $this->getResourceKey() && LocaleCopyHandlerInterface::ACTION === $action;
    }

    public function getSecurityContext(string $id, string $locale): ?string
    {
        $webspaceKey = $this->entityManager->createQueryBuilder()
            ->select('page.webspaceKey')
            ->from(PageInterface::class, 'page')
            ->where('page.uuid = :uuid')
            ->setParameter('uuid', $id)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_SINGLE_SCALAR);

        // an unknown page gets a context nobody has, never "no check"
        return PageAdmin::getPageSecurityContext((string) $webspaceKey);
    }

    public function getListSecurityContext(string $viewName): ?string
    {
        // the webspace of a list is not part of the view name; every entry is checked on its own
        return null;
    }

    public function getSecuredClass(): string
    {
        return Page::class;
    }

    public function findMissingInLocale(array $ids, string $locale): array
    {
        return $this->lookup->findMissingInLocale($ids, $locale);
    }

    public function getTitles(array $ids, string $locale): array
    {
        return $this->lookup->getTitles($ids, $locale);
    }

    protected function createTransitionMessage(array $identifier, string $locale, string $transition): object
    {
        return new ApplyWorkflowTransitionPageMessage($identifier, $locale, $transition);
    }

    protected function createRemoveMessage(array $identifier, string $locale): object
    {
        return new RemovePageMessage($identifier, $locale);
    }

    protected function createCopyLocaleMessage(array $identifier, string $sourceLocale, string $targetLocale): object
    {
        return new CopyLocalePageMessage($identifier, $sourceLocale, $targetLocale);
    }
}
