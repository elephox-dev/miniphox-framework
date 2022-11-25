<?php
declare(strict_types=1);

namespace Elephox\Miniphox\Middleware;

use Elephox\Files\File;
use Elephox\Files\Path;
use Elephox\Http\Response;
use Elephox\Mimey\MimeType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class StaticFileServerMiddleware
{
    public function __construct(
        private readonly string $root,
    )
    {
    }

    public function __invoke(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $physicalFile = File::from(Path::join($this->root, $path));
        if ($physicalFile->exists() && !in_array($physicalFile->extension(), ['php', 'phtml'])) {
            return Response::build()
                ->fileBody($physicalFile, MimeType::tryFromExtension($physicalFile->extension()))
                ->get();
        }

        return $next($request);
    }
}
