<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Controller\Admin;

use Manuxi\SuluBulkActionsBundle\Admin\BulkActionsAdmin;
use Manuxi\SuluBulkActionsBundle\Handler\BulkActionHandlerRegistry;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /admin/api/bulk-actions/{resourceKey}/{action}   body: {"ids": [...]}   query: locale
 *
 * Two checks: the bulk context ("live" for publish and unpublish, "delete" for delete) decides whether the user may
 * use bulk actions at all; then every entry is checked against its own security context (for articles the one of its
 * group), so nobody can do in bulk what they may not do one by one. Entries without permission are skipped.
 *
 * Own prefix on purpose: a path below /admin/api/{resourceKey}/ would collide with the routes of the resources
 * themselves (for example POST /admin/api/articles/{uuid}).
 */
class BulkActionController
{
    public function __construct(
        private readonly BulkActionHandlerRegistry $handlerRegistry,
        private readonly SecurityCheckerInterface $securityChecker,
        private readonly bool $deleteEnabled,
    ) {
    }

    #[Route('/bulk-actions/{resourceKey}/{action}', name: 'execute', requirements: ['action' => 'publish|unpublish|delete'], methods: ['POST'])]
    public function execute(string $resourceKey, string $action, Request $request): JsonResponse
    {
        if ('delete' === $action && !$this->deleteEnabled) {
            return new JsonResponse(['error' => 'Bulk delete is disabled (sulu_bulk_actions.delete_enabled).'], Response::HTTP_FORBIDDEN);
        }

        $permission = 'delete' === $action ? PermissionTypes::DELETE : PermissionTypes::LIVE;
        $this->securityChecker->checkPermission(BulkActionsAdmin::SECURITY_CONTEXT, $permission);

        $data = json_decode($request->getContent(), true);
        $ids = \is_array($data) ? array_values(array_filter($data['ids'] ?? [], 'is_string')) : [];

        if ([] === $ids) {
            return new JsonResponse(['error' => 'No IDs provided.'], Response::HTTP_BAD_REQUEST);
        }

        $handler = $this->handlerRegistry->find($resourceKey, $action);
        if (null === $handler) {
            return new JsonResponse(['error' => \sprintf('No handler for "%s" and action "%s".', $resourceKey, $action)], Response::HTTP_NOT_FOUND);
        }

        $locale = $request->query->getString('locale', $request->getLocale());

        $allowed = [];
        $denied = [];
        foreach ($ids as $id) {
            $context = $handler->getSecurityContext($id, $locale);
            if (null === $context || $this->securityChecker->hasPermission($context, $permission)) {
                $allowed[] = $id;
            } else {
                $denied[] = $id;
            }
        }

        $result = [] === $allowed ? ['done' => [], 'failed' => []] : $handler->handle($action, $allowed, $locale);
        $summary = [
            'done' => \count($result['done']),
            'denied' => \count($denied),
            'failed' => \count($result['failed']),
        ];

        if (0 === $summary['done']) {
            $message = [] !== $result['failed']
                ? \sprintf('%d entries failed: %s', $summary['failed'], implode('; ', \array_slice($result['failed'], 0, 3)))
                : 'You have no permission for the selected entries.';

            return new JsonResponse(
                ['error' => $message] + $summary,
                [] !== $result['failed'] ? Response::HTTP_INTERNAL_SERVER_ERROR : Response::HTTP_FORBIDDEN,
            );
        }

        return new JsonResponse(['success' => true] + $summary);
    }
}
