<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Tests\Fixtures;

use Manuxi\SuluBulkActionsBundle\Handler\BulkActionHandlerInterface;
use Manuxi\SuluBulkActionsBundle\Handler\EntryInfoProviderInterface;

/**
 * Handler for the resource "items" with fixed answers: which entries exist in which locales, which fail.
 */
class FakeBulkActionHandler implements BulkActionHandlerInterface, EntryInfoProviderInterface
{
    /** @var list<string> */
    public array $handledIds = [];

    /**
     * @param array<string, list<string>> $locales ID => locales with content
     * @param array<string, string> $titles ID => title
     * @param array<string, string> $failures ID => message of the exception
     * @param array<string, string> $contexts ID => security context
     */
    public function __construct(
        private readonly array $locales,
        private readonly array $titles = [],
        private readonly array $failures = [],
        private readonly array $contexts = [],
    ) {
    }

    public function supports(string $resourceKey, string $action): bool
    {
        return 'items' === $resourceKey;
    }

    public function getSecurityContext(string $id, string $locale): ?string
    {
        return $this->contexts[$id] ?? null;
    }

    public function getListSecurityContext(string $viewName): ?string
    {
        return null;
    }

    public function findMissingInLocale(array $ids, string $locale): array
    {
        return array_values(array_filter($ids, fn (string $id): bool => !\in_array($locale, $this->locales[$id] ?? [], true)));
    }

    public function getTitles(array $ids, string $locale): array
    {
        return array_intersect_key($this->titles, array_flip($ids));
    }

    public function handle(string $action, array $ids, string $locale): array
    {
        $this->handledIds = $ids;
        $done = [];
        $failed = [];

        foreach ($ids as $id) {
            if (isset($this->failures[$id])) {
                $failed[$id] = $this->failures[$id];
            } else {
                $done[] = $id;
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }
}
