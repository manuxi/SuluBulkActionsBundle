<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Handler;

/**
 * Executes a bulk action for the entities of one resource key.
 *
 * Register an implementation as a service with the tag "sulu_bulk_actions.handler" (autoconfigured).
 */
interface BulkActionHandlerInterface
{
    public function supports(string $resourceKey, string $action): bool;

    /**
     * @param list<string> $ids
     *
     * @return array<string, mixed> free-form result, returned to the admin
     */
    public function handle(string $action, array $ids, string $locale): array;
}
