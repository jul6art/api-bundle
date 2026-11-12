<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Test;

/**
 * What an API Platform configuration must say about who may call it: a default rule for every
 * operation that writes none (`api_platform.defaults.security`), and a rule on each operation.
 * Each violation is a sentence that says what is wrong and what it opens.
 */
final class ApiExposureRules
{
    /**
     * @param array<string, mixed>      $apiPlatform the raw `api_platform` configuration of production
     * @param iterable<string, ?string> $operations  each operation's name and its `security` expression
     *
     * @return list<string>
     */
    public static function violations(array $apiPlatform, iterable $operations): array
    {
        $violations = [];

        $defaults = $apiPlatform['defaults'] ?? null;
        $default = \is_array($defaults) ? ($defaults['security'] ?? null) : null;
        if (!\is_string($default) || '' === trim($default)) {
            $violations[] = 'api_platform.defaults.security is not set: an operation that forgets its own rule is open to anyone.';
        }

        foreach ($operations as $operation => $security) {
            if (null === $security || '' === trim($security)) {
                $violations[] = \sprintf('Operation "%s" has no security rule: anyone may call it.', $operation);
            }
        }

        return $violations;
    }
}
