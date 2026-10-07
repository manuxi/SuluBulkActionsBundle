<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Tests\Fixtures;

use Manuxi\SuluBulkActionsBundle\Handler\LocaleCopyHandlerInterface;

/**
 * FakeBulkActionHandler that also copies locales; remembers the locales of the last copy.
 */
class FakeLocaleCopyHandler extends FakeBulkActionHandler implements LocaleCopyHandlerInterface
{
    /** @var array{string, string}|null source and target locale of the last copy */
    public ?array $copiedLocales = null;

    public function copyLocale(array $ids, string $sourceLocale, string $targetLocale): array
    {
        $this->copiedLocales = [$sourceLocale, $targetLocale];

        return $this->handle(self::ACTION, $ids, $targetLocale);
    }
}
