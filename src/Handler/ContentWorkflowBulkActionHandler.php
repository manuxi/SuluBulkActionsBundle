<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Handler;

use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Base for content entities of Sulu 3 (articles, snippets, ...): publishing is a workflow transition,
 * deleting is a remove message. One message per entity, each flushed on its own, so one failing entity
 * does not undo the ones before it.
 */
abstract class ContentWorkflowBulkActionHandler implements BulkActionHandlerInterface
{
    public const ACTIONS = ['publish', 'unpublish', 'delete'];

    public function __construct(private readonly MessageBusInterface $messageBus)
    {
    }

    abstract protected function getResourceKey(): string;

    /**
     * @param array{uuid: string} $identifier
     */
    abstract protected function createTransitionMessage(array $identifier, string $locale, string $transition): object;

    /**
     * @param array{uuid: string} $identifier
     */
    abstract protected function createRemoveMessage(array $identifier, string $locale): object;

    public function supports(string $resourceKey, string $action): bool
    {
        return $resourceKey === $this->getResourceKey() && \in_array($action, self::ACTIONS, true);
    }

    public function handle(string $action, array $ids, string $locale): array
    {
        $done = [];
        $failed = [];

        foreach ($ids as $id) {
            $identifier = ['uuid' => (string) $id];

            try {
                $message = 'delete' === $action
                    ? $this->createRemoveMessage($identifier, $locale)
                    : $this->createTransitionMessage($identifier, $locale, $action);

                $this->messageBus->dispatch(new Envelope($message, [new EnableFlushStamp()]));
                $done[] = (string) $id;
            } catch (\Throwable $exception) {
                $failed[(string) $id] = $exception->getMessage();
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }
}
