<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Admin;

use Sulu\Bundle\AdminBundle\Admin\Admin;
use Sulu\Bundle\AdminBundle\Admin\View\ListViewBuilderInterface;
use Sulu\Bundle\AdminBundle\Admin\View\ToolbarAction;
use Sulu\Bundle\AdminBundle\Admin\View\ViewCollection;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;

/**
 * Adds the bulk actions dropdown to lists that were built by other admins (articles, snippets, ...).
 *
 * The bulk context has its own permissions, so the right to publish or delete many entries at once is separate from
 * the right to do it one by one: "live" allows publish and unpublish, "delete" allows delete (and delete is
 * additionally switched off by default, see the configuration).
 */
class BulkActionsAdmin extends Admin
{
    public const SECURITY_CONTEXT = 'sulu.bulk_actions.actions';

    /**
     * @param array<string, array{view_prefixes: list<string>, actions: list<string>}> $resources
     */
    public function __construct(
        private readonly SecurityCheckerInterface $securityChecker,
        private readonly array $resources,
        private readonly bool $deleteEnabled,
    ) {
    }

    /**
     * Runs after the admins that build the lists.
     */
    public static function getPriority(): int
    {
        return -1000;
    }

    public function configureViews(ViewCollection $viewCollection): void
    {
        foreach ($viewCollection->all() as $name => $viewBuilder) {
            if (!$viewBuilder instanceof ListViewBuilderInterface) {
                continue;
            }

            $actions = $this->actionsFor($name);
            if ([] === $actions) {
                continue;
            }

            $viewBuilder->addToolbarActions([
                new ToolbarAction('sulu_bulk_actions.actions', ['actions' => $actions]),
            ]);
        }
    }

    public function getSecurityContexts(): array
    {
        return [
            self::SULU_ADMIN_SECURITY_SYSTEM => [
                'BulkActions' => [
                    self::SECURITY_CONTEXT => [
                        PermissionTypes::LIVE,
                        PermissionTypes::DELETE,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function actionsFor(string $viewName): array
    {
        $actions = [];

        foreach ($this->resources as $resource) {
            foreach ($resource['view_prefixes'] as $prefix) {
                if (!str_starts_with($viewName, $prefix)) {
                    continue;
                }

                foreach ($resource['actions'] as $action) {
                    $actions[$action] = true;
                }
            }
        }

        return array_values(array_filter(
            array_keys($actions),
            fn (string $action): bool => $this->isAllowed($action),
        ));
    }

    private function isAllowed(string $action): bool
    {
        if ('delete' === $action) {
            return $this->deleteEnabled
                && $this->securityChecker->hasPermission(self::SECURITY_CONTEXT, PermissionTypes::DELETE);
        }

        return $this->securityChecker->hasPermission(self::SECURITY_CONTEXT, PermissionTypes::LIVE);
    }
}
