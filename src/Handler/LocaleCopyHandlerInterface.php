<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Handler;

/**
 * For handlers that offer the action "copy_locale": copies the content of one locale into another, like "Copy locale"
 * in the toolbar of the form, but for many entries at once.
 *
 * The result is a draft in the target locale; nothing is published. The controller skips entries without the source
 * locale and (unless overwriting is requested) entries that already have the target locale before calling
 * copyLocale(), using the methods of EntryInfoProviderInterface.
 */
interface LocaleCopyHandlerInterface extends EntryInfoProviderInterface
{
    public const ACTION = 'copy_locale';

    /**
     * @param list<string> $ids only entries the user may act on
     *
     * @return array{done: list<string>, failed: array<string, string>}
     */
    public function copyLocale(array $ids, string $sourceLocale, string $targetLocale): array;
}
