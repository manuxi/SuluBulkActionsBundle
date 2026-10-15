<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Handler;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Sulu\Bundle\FormBundle\Manager\FormManager;

/**
 * Forms of the SuluFormBundle: only "copy_locale". A form is not a content entity (no workflow, no draft or live
 * version), so publish and unpublish do not exist; deleting works in the list of the forms itself.
 *
 * The form manager copies the texts of the form, its receivers and its fields like "Copy locale" in the form.
 * The target locale is overwritten if it exists; the controller skips such forms unless overwriting is requested.
 */
class FormBulkActionHandler implements BulkActionHandlerInterface, LocaleCopyHandlerInterface
{
    public const RESOURCE_KEY = 'forms';

    private const SECURITY_CONTEXT = 'sulu.form.forms';

    public function __construct(
        private readonly FormManager $formManager,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function supports(string $resourceKey, string $action): bool
    {
        return self::RESOURCE_KEY === $resourceKey && LocaleCopyHandlerInterface::ACTION === $action;
    }

    public function getSecurityContext(string $id, string $locale): ?string
    {
        return self::SECURITY_CONTEXT;
    }

    public function getListSecurityContext(string $viewName): ?string
    {
        return self::SECURITY_CONTEXT;
    }

    public function handle(string $action, array $ids, string $locale): array
    {
        throw new \InvalidArgumentException(\sprintf('Unknown action "%s" for forms ("copy_locale" goes through copyLocale()).', $action));
    }

    public function copyLocale(array $ids, string $sourceLocale, string $targetLocale): array
    {
        $done = [];
        $failed = [];

        foreach ($ids as $id) {
            try {
                $this->formManager->copyLocale((int) $id, $sourceLocale, [$targetLocale]);
                $done[] = (string) $id;
            } catch (\Throwable $exception) {
                $failed[(string) $id] = $exception->getMessage();
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }

    public function findMissingInLocale(array $ids, string $locale): array
    {
        if ([] === $ids) {
            return [];
        }

        $found = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT idForms FROM fo_form_translations WHERE locale = :locale AND idForms IN (:ids)',
            ['locale' => $locale, 'ids' => array_map('intval', $ids)],
            ['ids' => ArrayParameterType::INTEGER],
        );
        $found = array_map('strval', $found);

        return array_values(array_filter($ids, static fn (string $id): bool => !\in_array((string) $id, $found, true)));
    }

    public function getTitles(array $ids, string $locale): array
    {
        if ([] === $ids) {
            return [];
        }

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT idForms, locale, title FROM fo_form_translations WHERE idForms IN (:ids)',
            ['ids' => array_map('intval', $ids)],
            ['ids' => ArrayParameterType::INTEGER],
        );

        // the title in the locale; without a translation in it the first one of another locale
        $titles = [];
        foreach ($rows as $row) {
            $id = (string) $row['idForms'];
            if ('' === (string) $row['title']) {
                continue;
            }

            if ($row['locale'] === $locale || !isset($titles[$id])) {
                $titles[$id] = (string) $row['title'];
            }
        }

        return $titles;
    }
}
