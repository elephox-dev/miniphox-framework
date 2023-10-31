<?php
declare(strict_types=1);

namespace Elephox\Miniphox\Services;

use Elephox\DI\Contract\Disposable;
use Psr\Http\Message\ServerRequestInterface;

class RequestProviderService implements Disposable
{
    private ?ServerRequestInterface $request;

    public function setRequest(?ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function getRequest(): ?ServerRequestInterface
    {
        return $this->request;
    }

    public function clearRequest(): void
    {
        $this->request = null;
    }

    public function dispose(): void
    {
        $this->clearRequest();
    }
}