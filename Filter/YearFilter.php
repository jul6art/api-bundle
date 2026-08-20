<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * Filtre `?<property>=YYYY` qui mappe vers `YEAR(<alias>.<property>) = YYYY`.
 * Utilisé par les datatables qui exposent un select Année (journal,
 * factures, etc.) sans recourir à un range explicit.
 */
final class YearFilter extends AbstractFilter
{
    protected function filterProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if (!$this->isPropertyEnabled($property, $resourceClass)) {
            return;
        }
        if (!\is_string($value) && !\is_int($value)) {
            return;
        }
        $year = (int) $value;
        if ($year < 1900 || $year > 9999) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $fromParam = $queryNameGenerator->generateParameterName($property.'_year_from');
        $toParam = $queryNameGenerator->generateParameterName($property.'_year_to');

        // Range half-open `[YYYY-01-01, YYYY+1-01-01)` plutôt que
        // `YEAR()` qui n'est pas portable (MySQL only ; Postgres lève
        // SQLSTATE[42883]). La forme range reste indexable et marche
        // pour `date` comme pour `datetime_immutable`.
        // Cf. `docs/corrections/2026-05-10-3.md` § P4.
        $queryBuilder
            ->andWhere(\sprintf(
                '%s.%s >= :%s AND %s.%s < :%s',
                $alias,
                $property,
                $fromParam,
                $alias,
                $property,
                $toParam,
            ))
            ->setParameter($fromParam, new \DateTimeImmutable($year.'-01-01 00:00:00'))
            ->setParameter($toParam, new \DateTimeImmutable(($year + 1).'-01-01 00:00:00'));
    }

    /** @return array<string, array<string, mixed>> */
    public function getDescription(string $resourceClass): array
    {
        $description = [];
        foreach (array_keys($this->getProperties() ?? []) as $property) {
            $key = (string) $property;
            $description[$key] = [
                'property' => $key,
                'type' => 'integer',
                'required' => false,
                'description' => 'Filter by year (YYYY) on the date column.',
            ];
        }

        return $description;
    }
}
