<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Tests\Fixtures;

/**
 * Stand-in for the FormManager of the SuluFormBundle (aliased in the tests, the bundle is not installed here): remembers
 * the copies and fails for the IDs it is told to fail for.
 */
class FakeFormManager
{
    /** @var list<array{int, string, list<string>}> */
    public array $copies = [];

    /** @var list<int> */
    public array $failingIds = [];

    /**
     * @param list<string> $destLocales
     */
    public function copyLocale(int $id, string $srcLocale, array $destLocales): object
    {
        if (\in_array($id, $this->failingIds, true)) {
            throw new \RuntimeException(\sprintf('Form %d cannot be copied.', $id));
        }

        $this->copies[] = [$id, $srcLocale, $destLocales];

        return new \stdClass();
    }
}
