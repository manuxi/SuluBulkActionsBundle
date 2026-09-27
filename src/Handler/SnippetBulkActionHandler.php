<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Handler;

use Sulu\Snippet\Application\Message\ApplyWorkflowTransitionSnippetMessage;
use Sulu\Snippet\Application\Message\RemoveSnippetMessage;

class SnippetBulkActionHandler extends ContentWorkflowBulkActionHandler
{
    protected function getResourceKey(): string
    {
        return 'snippets';
    }

    protected function createTransitionMessage(array $identifier, string $locale, string $transition): object
    {
        return new ApplyWorkflowTransitionSnippetMessage($identifier, $locale, $transition);
    }

    protected function createRemoveMessage(array $identifier, string $locale): object
    {
        return new RemoveSnippetMessage($identifier, $locale);
    }
}
