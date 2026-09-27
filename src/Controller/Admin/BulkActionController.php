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

        // publish and unpublish need the "live" permission, delete the "delete" permission of the bulk context
        $this->securityChecker->checkPermission(
            BulkActionsAdmin::SECURITY_CONTEXT,
            'delete' === $action ? PermissionTypes::DELETE : PermissionTypes::LIVE,
        );

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
        $result = $handler->handle($action, $ids, $locale);

        $failed = $result['failed'] ?? [];
        if ([] !== $failed) {
            return new JsonResponse(
                ['error' => \sprintf('%d of %d entries failed: %s', \count($failed), \count($ids), implode('; ', array_slice($failed, 0, 3))), 'result' => $result],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return new JsonResponse(['success' => true, 'count' => \count($ids), 'result' => $result]);
    }
}
