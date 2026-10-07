<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Admin;

use Manuxi\SuluBulkActionsBundle\Handler\BulkActionHandlerRegistry;
use Sulu\Bundle\AdminBundle\Admin\Admin;
use Sulu\Bundle\AdminBundle\Admin\View\ListViewBuilderInterface;
use Sulu\Bundle\AdminBundle\Admin\View\ToolbarAction;
use Sulu\Bundle\AdminBundle\Admin\View\ViewCollection;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;

/**
 * Adds the bulk actions dropdown to lists that were built by other admins (articles, snippets, ...).
 *
 * Two levels of permissions: the bulk context decides whether a user may use bulk actions at all ("live" allows
 * publish and unpublish, "edit" allows copying a locale, "delete" allows delete, and delete is additionally switched
 * off by default, see the configuration). Then an action is only offered in a list if the user also has the same
 * permission in the security context of that list (for articles the one of the group); the server checks every entry
 * again.
 */
class BulkActionsAdmin extends Admin
{
    public const SECURITY_CONTEXT = 'sulu.bulk_actions.actions';

    /**
     * The permission an action needs, in the bulk context and in the context of every entry.
     */
    public static function getPermission(string $action): string
    {
        return match ($action) {
            'delete' => PermissionTypes::DELETE,
            'copy_locale' => PermissionTypes::EDIT,
            default => PermissionTypes::LIVE,
        };
    }

    /**
     * @param array<string, array{view_prefixes: list<string>, actions: list<string>}> $resources
     */
    public function __construct(
        private readonly SecurityCheckerInterface $securityChecker,
        private readonly BulkActionHandlerRegistry $handlerRegistry,
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
                        PermissionTypes::EDIT,
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

        foreach ($this->resources as $resourceKey => $resource) {
            foreach ($resource['view_prefixes'] as $prefix) {
                if (!str_starts_with($viewName, $prefix)) {
                    continue;
                }

                foreach ($resource['actions'] as $action) {
                    if ($this->isAllowed((string) $resourceKey, $action, $viewName)) {
                        $actions[$action] = true;
                    }
                }
            }
        }

        return array_keys($actions);
    }

    private function isAllowed(string $resourceKey, string $action, string $viewName): bool
    {
        $permission = self::getPermission($action);

        if ('delete' === $action && !$this->deleteEnabled) {
            return false;
        }

        $handler = $this->handlerRegistry->find($resourceKey, $action);
        if (null === $handler) {
            return false;
        }

        if (!$this->securityChecker->hasPermission(self::SECURITY_CONTEXT, $permission)) {
            return false;
        }

        $context = $handler->getListSecurityContext($viewName);

        return null === $context || $this->securityChecker->hasPermission($context, $permission);
    }
}
