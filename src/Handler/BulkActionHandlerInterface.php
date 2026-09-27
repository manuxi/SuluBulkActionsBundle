<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Handler;

/**
 * Executes a bulk action for the entities of one resource key.
 *
 * Register an implementation as a service with the tag "sulu_bulk_actions.handler" (autoconfigured).
 *
 * Besides the bulk context, every entry is checked against the security context of the entry itself ("live" for
 * publish and unpublish, "delete" for delete), so nobody can do in bulk what they may not do one by one.
 */
interface BulkActionHandlerInterface
{
    public function supports(string $resourceKey, string $action): bool;

    /**
     * Security context of one entry, for example the context of its article group. Null means: no check of the entry.
     */
    public function getSecurityContext(string $id, string $locale): ?string;

    /**
     * Security context of a list view (the tab of an article group), to show the actions only where they are allowed.
     * Null means: no check of the list.
     */
    public function getListSecurityContext(string $viewName): ?string;

    /**
     * @param list<string> $ids only entries the user may act on
     *
     * @return array{done: list<string>, failed: array<string, string>}
     */
    public function handle(string $action, array $ids, string $locale): array;
}
