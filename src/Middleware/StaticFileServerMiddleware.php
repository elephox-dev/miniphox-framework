<?php
declare(strict_types=1);

namespace Elephox\Miniphox\Middleware;

use Elephox\Files\File;
use Elephox\Files\Path;
use Elephox\Http\Response;
use Elephox\Mimey\MimeType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class StaticFileServerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $root,
    )
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $physicalFile = File::from(Path::join($this->root, $path));
        if ($physicalFile->exists() && !in_array($physicalFile->extension(), ['php', 'phtml'], true)) {
            return Response::build()
                ->fileBody($physicalFile, MimeType::tryFromExtension($physicalFile->extension()))
                ->get();
        }

        return $handler->handle($request);
    }
}
