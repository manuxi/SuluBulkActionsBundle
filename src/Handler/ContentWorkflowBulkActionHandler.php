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
 *
 * Subclasses that also implement LocaleCopyHandlerInterface get "copy_locale" by overriding createCopyLocaleMessage().
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

    /**
     * The message Sulu itself sends for "Copy locale" in the form; only needed with LocaleCopyHandlerInterface.
     *
     * @param array{uuid: string} $identifier
     */
    protected function createCopyLocaleMessage(array $identifier, string $sourceLocale, string $targetLocale): object
    {
        throw new \LogicException(\sprintf('%s does not support copying locales.', static::class));
    }

    public function supports(string $resourceKey, string $action): bool
    {
        if ($resourceKey !== $this->getResourceKey()) {
            return false;
        }

        if (LocaleCopyHandlerInterface::ACTION === $action) {
            return $this instanceof LocaleCopyHandlerInterface;
        }

        return \in_array($action, self::ACTIONS, true);
    }

    public function handle(string $action, array $ids, string $locale): array
    {
        if (!\in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown action "%s" ("copy_locale" goes through copyLocale()).', $action));
        }

        return $this->dispatchEach($ids, fn (array $identifier): object => 'delete' === $action
            ? $this->createRemoveMessage($identifier, $locale)
            : $this->createTransitionMessage($identifier, $locale, $action));
    }

    /**
     * Implements LocaleCopyHandlerInterface::copyLocale() for the subclasses that declare it.
     *
     * @param list<string> $ids
     *
     * @return array{done: list<string>, failed: array<string, string>}
     */
    public function copyLocale(array $ids, string $sourceLocale, string $targetLocale): array
    {
        return $this->dispatchEach(
            $ids,
            fn (array $identifier): object => $this->createCopyLocaleMessage($identifier, $sourceLocale, $targetLocale),
        );
    }

    /**
     * @param list<string> $ids
     * @param callable(array{uuid: string}): object $createMessage
     *
     * @return array{done: list<string>, failed: array<string, string>}
     */
    private function dispatchEach(array $ids, callable $createMessage): array
    {
        $done = [];
        $failed = [];

        foreach ($ids as $id) {
            $identifier = ['uuid' => (string) $id];

            try {
                $this->messageBus->dispatch(new Envelope($createMessage($identifier), [new EnableFlushStamp()]));
                $done[] = (string) $id;
            } catch (\Throwable $exception) {
                $failed[(string) $id] = $exception->getMessage();
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }
}
