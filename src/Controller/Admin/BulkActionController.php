<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Controller\Admin;

use Manuxi\SuluBulkActionsBundle\Admin\BulkActionsAdmin;
use Manuxi\SuluBulkActionsBundle\Handler\BulkActionHandlerInterface;
use Manuxi\SuluBulkActionsBundle\Handler\BulkActionHandlerRegistry;
use Manuxi\SuluBulkActionsBundle\Handler\EntryInfoProviderInterface;
use Manuxi\SuluBulkActionsBundle\Handler\LocaleCopyHandlerInterface;
use Manuxi\SuluBulkActionsBundle\Handler\SecuredObjectHandlerInterface;
use Sulu\Component\Localization\Manager\LocalizationManagerInterface;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Security\Authorization\SecurityCondition;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /admin/api/bulk-actions/{resourceKey}/{action}   body: {"ids": [...]}   query: locale
 *
 * Two checks: the bulk context ("live" for publish and unpublish, "edit" for copy_locale, "delete" for delete) decides
 * whether the user may use bulk actions at all; then every entry is checked against its own security context (for
 * articles the one of its group), so nobody can do in bulk what they may not do one by one. Entries without permission
 * are skipped.
 *
 * Before that, entries without content in the locale are skipped (if the handler can tell, see
 * EntryInfoProviderInterface): they would only fail in the workflow with a technical message.
 *
 * copy_locale additionally takes "sourceLocale", "targetLocale" and "overwrite" in the body. Entries that already have
 * the target locale are skipped unless "overwrite" is true, and the permission is checked in the target locale.
 *
 * The response counts every category (done, missing, denied, failed, for copy_locale also existing) and lists the
 * failures and skipped entries with their titles, so the admin can explain the result in the language of the user.
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
        private readonly ?LocalizationManagerInterface $localizationManager = null,
    ) {
    }

    #[Route('/bulk-actions/{resourceKey}/{action}', name: 'execute', requirements: ['action' => 'publish|unpublish|delete|copy_locale'], methods: ['POST'])]
    public function execute(string $resourceKey, string $action, Request $request): JsonResponse
    {
        if ('delete' === $action && !$this->deleteEnabled) {
            return new JsonResponse(['error' => 'Bulk delete is disabled (sulu_bulk_actions.delete_enabled).'], Response::HTTP_FORBIDDEN);
        }

        $permission = BulkActionsAdmin::getPermission($action);
        $this->securityChecker->checkPermission(BulkActionsAdmin::SECURITY_CONTEXT, $permission);

        $data = json_decode($request->getContent(), true);
        $data = \is_array($data) ? $data : [];
        $ids = array_values(array_filter(\is_array($data['ids'] ?? null) ? $data['ids'] : [], 'is_string'));

        if ([] === $ids) {
            return new JsonResponse(['error' => 'No IDs provided.'], Response::HTTP_BAD_REQUEST);
        }

        $handler = $this->handlerRegistry->find($resourceKey, $action);
        $isCopy = LocaleCopyHandlerInterface::ACTION === $action;
        if (null === $handler || ($isCopy && !$handler instanceof LocaleCopyHandlerInterface)) {
            return new JsonResponse(['error' => \sprintf('No handler for "%s" and action "%s".', $resourceKey, $action)], Response::HTTP_NOT_FOUND);
        }

        $locale = $request->query->getString('locale', $request->getLocale());

        if ($isCopy && $handler instanceof LocaleCopyHandlerInterface) {
            return $this->copyLocale($handler, $ids, $data, $locale, $permission);
        }

        $missing = $handler instanceof EntryInfoProviderInterface
            ? array_values(array_intersect($ids, $handler->findMissingInLocale($ids, $locale)))
            : [];

        [$allowed, $denied] = $this->checkEntries($handler, array_diff($ids, $missing), $locale, $permission);

        $result = [] === $allowed ? ['done' => [], 'failed' => []] : $handler->handle($action, $allowed, $locale);

        $titles = $handler instanceof EntryInfoProviderInterface && [] !== $result['failed']
            ? $handler->getTitles(array_map('strval', array_keys($result['failed'])), $locale)
            : [];

        return $this->respond([
            'done' => \count($result['done']),
            'missing' => \count($missing),
            'denied' => \count($denied),
            'failed' => \count($result['failed']),
            'locale' => $locale,
            'failures' => $this->listFailures($result['failed'], $titles),
        ]);
    }

    /**
     * @param list<string> $ids
     * @param array<string, mixed> $data
     */
    private function copyLocale(LocaleCopyHandlerInterface $handler, array $ids, array $data, string $locale, string $permission): JsonResponse
    {
        $sourceLocale = \is_string($data['sourceLocale'] ?? null) && '' !== $data['sourceLocale'] ? $data['sourceLocale'] : $locale;
        $targetLocale = \is_string($data['targetLocale'] ?? null) ? $data['targetLocale'] : '';
        $overwrite = true === ($data['overwrite'] ?? false);

        $error = $this->validateLocales($sourceLocale, $targetLocale);
        if (null !== $error) {
            return new JsonResponse(['error' => $error], Response::HTTP_BAD_REQUEST);
        }

        $missing = array_values(array_intersect($ids, $handler->findMissingInLocale($ids, $sourceLocale)));
        $candidates = array_values(array_diff($ids, $missing));

        // without "overwrite" an existing target stays as it is: copying would replace its draft
        $existing = $overwrite || [] === $candidates
            ? []
            : array_values(array_diff($candidates, $handler->findMissingInLocale($candidates, $targetLocale)));

        [$allowed, $denied] = $this->checkEntries($handler, array_diff($candidates, $existing), $sourceLocale, $permission, $targetLocale);

        $result = [] === $allowed ? ['done' => [], 'failed' => []] : $handler->copyLocale($allowed, $sourceLocale, $targetLocale);

        $named = array_merge($missing, $existing, array_map('strval', array_keys($result['failed'])));
        $titles = [] === $named ? [] : $handler->getTitles($named, $sourceLocale);

        $skipped = [];
        foreach (['missing' => $missing, 'existing' => $existing] as $reason => $skippedIds) {
            foreach ($skippedIds as $id) {
                $skipped[] = ['id' => $id, 'title' => $titles[$id] ?? null, 'reason' => $reason];
            }
        }

        return $this->respond([
            'done' => \count($result['done']),
            'missing' => \count($missing),
            'existing' => \count($existing),
            'denied' => \count($denied),
            'failed' => \count($result['failed']),
            'locale' => $sourceLocale,
            'targetLocale' => $targetLocale,
            'skipped' => $skipped,
            'failures' => $this->listFailures($result['failed'], $titles),
        ]);
    }

    private function validateLocales(string $sourceLocale, string $targetLocale): ?string
    {
        if ('' === $targetLocale) {
            return 'No target locale provided (targetLocale).';
        }

        if ($sourceLocale === $targetLocale) {
            return 'The target locale must differ from the source locale.';
        }

        $locales = $this->localizationManager?->getLocales();
        if (null !== $locales) {
            foreach ([$sourceLocale, $targetLocale] as $candidate) {
                if (!\in_array($candidate, $locales, true)) {
                    return \sprintf('Unknown locale "%s"; known are: %s.', $candidate, implode(', ', $locales));
                }
            }
        }

        return null;
    }

    /**
     * Splits the entries into allowed and denied. The security context is read in $contextLocale (for articles the
     * group of the template in that locale); the permission is checked in $permissionLocale if given (copy_locale:
     * the target locale, which counts for roles restricted to some locales).
     *
     * @param iterable<string> $ids
     *
     * @return array{list<string>, list<string>}
     */
    private function checkEntries(BulkActionHandlerInterface $handler, iterable $ids, string $contextLocale, string $permission, ?string $permissionLocale = null): array
    {
        $allowed = [];
        $denied = [];

        foreach ($ids as $id) {
            $context = $handler->getSecurityContext($id, $contextLocale);
            if (null === $context || $this->securityChecker->hasPermission($this->createSubject($handler, $id, $context, $permissionLocale), $permission)) {
                $allowed[] = $id;
            } else {
                $denied[] = $id;
            }
        }

        return [$allowed, $denied];
    }

    private function createSubject(BulkActionHandlerInterface $handler, string $id, string $context, ?string $locale): string|SecurityCondition
    {
        if ($handler instanceof SecuredObjectHandlerInterface) {
            return new SecurityCondition($context, $locale, $handler->getSecuredClass(), $id);
        }

        return null === $locale ? $context : new SecurityCondition($context, $locale);
    }

    /**
     * @param array<string, string> $failed ID => message
     * @param array<string, string> $titles ID => title
     *
     * @return list<array{id: string, title: ?string, message: string}>
     */
    private function listFailures(array $failed, array $titles): array
    {
        $failures = [];
        foreach ($failed as $id => $message) {
            $failures[] = ['id' => (string) $id, 'title' => $titles[$id] ?? null, 'message' => $message];
        }

        return $failures;
    }

    /**
     * @param array{done: int, missing: int, existing?: int, denied: int, failed: int, locale: string, failures: list<array{id: string, title: ?string, message: string}>} $summary
     */
    private function respond(array $summary): JsonResponse
    {
        if (0 === $summary['done']) {
            $status = match (true) {
                [] !== $summary['failures'] => Response::HTTP_INTERNAL_SERVER_ERROR,
                $summary['missing'] > 0, ($summary['existing'] ?? 0) > 0 => Response::HTTP_UNPROCESSABLE_ENTITY,
                default => Response::HTTP_FORBIDDEN,
            };

            return new JsonResponse(['error' => $this->describe($summary)] + $summary, $status);
        }

        return new JsonResponse(['success' => true] + $summary);
    }

    /**
     * Plain English summary for clients of the API; the admin builds its own, translated text from the counts.
     *
     * @param array{missing: int, existing?: int, denied: int, failed: int, locale: string, targetLocale?: string, failures: list<array{id: string, title: ?string, message: string}>} $summary
     */
    private function describe(array $summary): string
    {
        $parts = [];

        if ($summary['missing'] > 0) {
            $parts[] = \sprintf(
                $this->plural($summary['missing'], '%d entry has no content in locale "%s" and was skipped.', '%d entries have no content in locale "%s" and were skipped.'),
                $summary['missing'],
                $summary['locale'],
            );
        }

        if (($summary['existing'] ?? 0) > 0) {
            $parts[] = \sprintf(
                $this->plural($summary['existing'], '%d entry already has content in locale "%s" and was not overwritten.', '%d entries already have content in locale "%s" and were not overwritten.'),
                $summary['existing'],
                $summary['targetLocale'] ?? '',
            );
        }

        if ($summary['denied'] > 0) {
            $parts[] = \sprintf($this->plural($summary['denied'], '%d entry was skipped: no permission.', '%d entries were skipped: no permission.'), $summary['denied']);
        }

        if ($summary['failed'] > 0) {
            $parts[] = \sprintf($this->plural($summary['failed'], '%d entry failed: %s', '%d entries failed: %s'), $summary['failed'], implode('; ', array_map(
                static fn (array $failure): string => \sprintf('%s: %s', $failure['title'] ?? $failure['id'], $failure['message']),
                \array_slice($summary['failures'], 0, 3),
            )));
        }

        return implode(' ', $parts);
    }

    private function plural(int $count, string $one, string $other): string
    {
        return 1 === $count ? $one : $other;
    }
}
