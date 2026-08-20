<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Api;

/**
 * Header names the API answers with, as constants rather than string literals.
 *
 * These two are genuinely generic: any rate limiter exposes a limit and a remainder, and the
 * names are conventional. They live here so that a CORS configuration, an OpenAPI description and
 * a test can all refer to the same thing — three places that drift the moment one of them holds a
 * literal.
 *
 * > ⚠️ **The tenant-scoping header is not here.** Its name belongs to the application: whether a
 * > request is scoped by `X-ORGANIZATION`, `X-TENANT` or `X-ACCOUNT` is a decision about a domain
 * > this bundle knows nothing about. It is configuration — `api.tenant_header` — and the
 * > container parameter `api.tenant_header` is what a subscriber, an OpenAPI factory or a CORS
 * > rule should read.
 * >
 * > That matters beyond tidiness: renaming it breaks every client, so it has to be visible in the
 * > application's own configuration rather than buried in a vendor constant.
 */
final class ApiHeaders
{
    /**
     * Requests allowed in the current window.
     */
    public const string RATE_LIMIT_LIMIT = 'X-RateLimit-Limit';

    /**
     * Requests still available in the current window.
     */
    public const string RATE_LIMIT_REMAINING = 'X-RateLimit-Remaining';
}
