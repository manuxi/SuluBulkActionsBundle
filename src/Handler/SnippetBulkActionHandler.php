<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Handler;

use Doctrine\ORM\EntityManagerInterface;
use Sulu\Snippet\Application\Message\ApplyWorkflowTransitionSnippetMessage;
use Sulu\Snippet\Application\Message\CopyLocaleSnippetMessage;
use Sulu\Snippet\Application\Message\RemoveSnippetMessage;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Infrastructure\Sulu\Admin\SnippetAdmin;
use Symfony\Component\Messenger\MessageBusInterface;

class SnippetBulkActionHandler extends ContentWorkflowBulkActionHandler implements LocaleCopyHandlerInterface
{
    private readonly DimensionContentLookup $lookup;

    public function __construct(MessageBusInterface $messageBus, EntityManagerInterface $entityManager)
    {
        parent::__construct($messageBus);
        $this->lookup = new DimensionContentLookup($entityManager, SnippetInterface::class);
    }

    protected function getResourceKey(): string
    {
        return 'snippets';
    }

    public function getSecurityContext(string $id, string $locale): ?string
    {
        return SnippetAdmin::SECURITY_CONTEXT;
    }

    public function getListSecurityContext(string $viewName): ?string
    {
        return SnippetAdmin::SECURITY_CONTEXT;
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
        return new ApplyWorkflowTransitionSnippetMessage($identifier, $locale, $transition);
    }

    protected function createRemoveMessage(array $identifier, string $locale): object
    {
        return new RemoveSnippetMessage($identifier, $locale);
    }

    protected function createCopyLocaleMessage(array $identifier, string $sourceLocale, string $targetLocale): object
    {
        return new CopyLocaleSnippetMessage($identifier, $sourceLocale, $targetLocale);
    }
}
