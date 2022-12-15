<?php
declare(strict_types=1);

namespace Elephox\Miniphox\Middleware;

class CorsOptions
{
    /**
     * @param string|list<string> $paths The path(s), which this policy applies to. For example `['api/*']`
     * @param list<string> $allowedMethods Matches the request method. `['*']` allows all methods.
     * @param list<string> $allowedOrigins The allowed origins. `['*']` allows any origin. Wildcards like `*.example.com` can be used.
     * @param list<string> $allowedHeaders Sets the Access-Control-Allow-Headers response header. `['*']` allows all headers.
     * @param list<string> $exposedHeaders Sets the Access-Control-Expose-Headers response header with these headers.
     * @param int $maxAge Sets the Access-Control-Max-Age response header when > 0.
     * @param bool $supportsCredentials Sets the Access-Control-Allow-Credentials header.
     */
    public function __construct(
        public readonly string|array $paths,
        public readonly array $allowedMethods = ['*'],
        public readonly array $allowedOrigins = ['*'],
        public readonly array $allowedHeaders = ['*'],
        public readonly array $exposedHeaders = [],
        public readonly int $maxAge = 0,
        public readonly bool $supportsCredentials = false,
    )
    {
    }
}
