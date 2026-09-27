<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Handler;

use Sulu\Article\Application\Message\ApplyWorkflowTransitionArticleMessage;
use Sulu\Article\Application\Message\RemoveArticleMessage;

class ArticleBulkActionHandler extends ContentWorkflowBulkActionHandler
{
    protected function getResourceKey(): string
    {
        return 'articles';
    }

    protected function createTransitionMessage(array $identifier, string $locale, string $transition): object
    {
        return new ApplyWorkflowTransitionArticleMessage($identifier, $locale, $transition);
    }

    protected function createRemoveMessage(array $identifier, string $locale): object
    {
        return new RemoveArticleMessage($identifier, $locale);
    }
}
