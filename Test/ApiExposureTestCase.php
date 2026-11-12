<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Test;

use ApiPlatform\Metadata\NotExposed;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use Jul6Art\CoreBundle\Test\KernelConfiguration;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every API operation says who may call it ({@see ApiExposureRules}): the production configuration
 * writes a default rule — closed, so that a forgotten one refuses instead of opening — and each
 * operation of the project carries a rule, its own or that default.
 *
 * A project extends it once, in `tests/Architecture/`:
 *
 * ```php
 * final class ApiOperationSecurityTest extends ApiExposureTestCase
 * {
 * }
 * ```
 *
 * The default is read from what the `prod` environment writes, without booting it (core-bundle's
 * `KernelConfiguration`); the operations from API Platform's metadata. API Platform's own
 * resources (its error representations) and the operations it creates only to build an IRI
 * (`NotExposed`) answer no request, and are left out.
 */
abstract class ApiExposureTestCase extends KernelTestCase
{
    public function testEveryApiOperationSaysWhoMayCallIt(): void
    {
        $violations = ApiExposureRules::violations(
            KernelConfiguration::of(static::getKernelClass(), 'prod')->extension('api_platform'),
            $this->operations(),
        );

        self::assertSame([], $violations, implode("\n", $violations));
    }

    /**
     * @return iterable<string, ?string>
     */
    private function operations(): iterable
    {
        $names = static::getContainer()->get(ResourceNameCollectionFactoryInterface::class);
        $metadata = static::getContainer()->get(ResourceMetadataCollectionFactoryInterface::class);
        self::assertInstanceOf(ResourceNameCollectionFactoryInterface::class, $names);
        self::assertInstanceOf(ResourceMetadataCollectionFactoryInterface::class, $metadata);

        foreach ($names->create() as $class) {
            if (str_starts_with($class, 'ApiPlatform\\')) {
                continue;
            }
            foreach ($metadata->create($class) as $resource) {
                foreach ([...($resource->getOperations() ?? []), ...($resource->getGraphQlOperations() ?? [])] as $operation) {
                    if ($operation instanceof NotExposed) {
                        continue;
                    }
                    yield $class.'::'.($operation->getName() ?? $operation::class) => $operation->getSecurity();
                }
            }
        }
    }
}
