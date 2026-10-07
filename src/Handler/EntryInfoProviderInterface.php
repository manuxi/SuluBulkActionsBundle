<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Handler;

/**
 * Optional for handlers: makes the result readable for editors.
 *
 * Entries without content in the chosen locale are skipped before the action runs and reported as a category of their
 * own ("no English version"), instead of failing with the raw exception of the content workflow. Titles replace the
 * IDs in the messages of entries that failed for other reasons.
 */
interface EntryInfoProviderInterface
{
    /**
     * @param list<string> $ids
     *
     * @return list<string> the IDs of the entries that have no content in the locale
     */
    public function findMissingInLocale(array $ids, string $locale): array;

    /**
     * @param list<string> $ids
     *
     * @return array<string, string> ID => title, in the locale if possible; entries without a title are left out
     */
    public function getTitles(array $ids, string $locale): array;
}
