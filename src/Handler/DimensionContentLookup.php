<?php

declare(strict_types=1);

namespace Manuxi\SuluBulkActionsBundle\Handler;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Sulu\Content\Domain\Model\DimensionContentInterface;

/**
 * Looks into the dimension contents of a content entity of Sulu 3 (articles, snippets, testimonials, events, ...):
 * which entries have a draft in a locale, and what are their titles.
 *
 * Scalar queries on purpose: loading the entities as objects would fill the identity map with some dimensions only,
 * and the workflow would then not find the live one (see ArticleBulkActionHandler).
 */
class DimensionContentLookup
{
    private const ASSOCIATION = 'dimensionContents';

    /**
     * @param class-string $entityClass the entity (or its interface), which has the association "dimensionContents"
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $entityClass,
    ) {
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public function findMissingInLocale(array $ids, string $locale): array
    {
        if ([] === $ids) {
            return [];
        }

        $found = $this->createQueryBuilder($ids)
            ->select('DISTINCT entity.'.$this->getIdField().' AS id')
            ->andWhere('dimensionContent.locale = :locale')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_diff($ids, array_map('strval', $found)));
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, string>
     */
    public function getTitles(array $ids, string $locale): array
    {
        if ([] === $ids || !$this->hasTitle()) {
            return [];
        }

        $rows = $this->createQueryBuilder($ids)
            ->select('entity.'.$this->getIdField().' AS id', 'dimensionContent.locale AS locale', 'dimensionContent.title AS title')
            ->andWhere('dimensionContent.locale IS NOT NULL')
            ->andWhere('dimensionContent.title IS NOT NULL')
            ->orderBy('dimensionContent.locale')
            ->getQuery()
            ->getArrayResult();

        $titles = [];
        foreach ($rows as $row) {
            $id = (string) $row['id'];
            $title = trim((string) $row['title']);
            if ('' === $title) {
                continue;
            }

            // the title in the locale wins, otherwise the first one found (an entry without this locale)
            if (!isset($titles[$id]) || $locale === $row['locale']) {
                $titles[$id] = $title;
            }
        }

        return $titles;
    }

    /**
     * @param list<string> $ids
     */
    private function createQueryBuilder(array $ids): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->from($this->entityClass, 'entity')
            ->innerJoin('entity.'.self::ASSOCIATION, 'dimensionContent')
            ->where('entity.'.$this->getIdField().' IN (:ids)')
            ->andWhere('dimensionContent.stage = :stage')
            ->andWhere('dimensionContent.version = :version')
            ->setParameter('ids', $ids)
            ->setParameter('stage', DimensionContentInterface::STAGE_DRAFT)
            ->setParameter('version', DimensionContentInterface::CURRENT_VERSION);
    }

    private function getIdField(): string
    {
        return $this->entityManager->getClassMetadata($this->entityClass)->getSingleIdentifierFieldName();
    }

    private function hasTitle(): bool
    {
        $dimensionContentClass = $this->entityManager->getClassMetadata($this->entityClass)
            ->getAssociationTargetClass(self::ASSOCIATION);

        return $this->entityManager->getClassMetadata($dimensionContentClass)->hasField('title');
    }
}
