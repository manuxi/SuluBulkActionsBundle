<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Handler;

class BulkActionHandlerRegistry
{
    /** @var list<BulkActionHandlerInterface> */
    private array $handlers = [];

    public function addHandler(BulkActionHandlerInterface $handler): void
    {
        $this->handlers[] = $handler;
    }

    public function find(string $resourceKey, string $action): ?BulkActionHandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($resourceKey, $action)) {
                return $handler;
            }
        }

        return null;
    }
}
