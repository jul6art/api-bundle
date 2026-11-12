<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Tests\Test;

use Jul6Art\ApiBundle\Test\ApiExposureRules;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiExposureRules::class)]
final class ApiExposureRulesTest extends TestCase
{
    private const array CLOSED = ['defaults' => ['security' => "is_granted('ROLE_NOBODY')"]];

    public function testAClosedDefaultAndRuledOperationsPass(): void
    {
        self::assertSame([], ApiExposureRules::violations(self::CLOSED, ['Book::get' => "is_granted('ROLE_USER')", 'Book::list' => "is_granted('ROLE_NOBODY')"]));
    }

    public function testAMissingDefaultIsNamed(): void
    {
        $violations = ApiExposureRules::violations(['defaults' => ['pagination_enabled' => true]], []);

        self::assertCount(1, $violations);
        self::assertStringStartsWith('api_platform.defaults.security', $violations[0]);
    }

    public function testAnOperationWithoutARuleIsNamed(): void
    {
        $violations = ApiExposureRules::violations(self::CLOSED, ['Book::get' => null, 'Book::post' => ' ']);

        self::assertSame(['Operation "Book::get" has no security rule: anyone may call it.', 'Operation "Book::post" has no security rule: anyone may call it.'], $violations);
    }
}
