<?php
declare(strict_types=1);

namespace Elephox\Miniphox\Services;

use LogicException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;

class LoggerProviderService implements LoggerAwareInterface
{
    private ?LoggerInterface $logger = null;

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger ?? throw new LogicException('Logger not set');
    }
}