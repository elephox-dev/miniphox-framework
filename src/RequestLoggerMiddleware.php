<?php
declare(strict_types=1);

namespace Elephox\Miniphox;

use Elephox\DI\Contract\ServiceCollection;
use Elephox\Logging\Contract\SinkLogger;
use Elephox\Logging\SinkCapability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

class RequestLoggerMiddleware {
    private readonly LoggerInterface $logger;
    private readonly bool $enhanced;

    public function __construct(
        ServiceCollection $services,
    )
    {
        $this->logger = $services->requireService(LoggerInterface::class);
        $this->enhanced = $this->logger instanceof SinkLogger && $this->logger->hasCapability(SinkCapability::ElephoxFormatting);
    }

    public function __invoke(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        /** @var ResponseInterface $response */
        $response = $next($request);

        $this->logRequest($request, $response);

        return $response;
    }

    private function logRequest(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $requestStart = $request->getServerParams()['REQUEST_TIME_FLOAT'] ?? null;
        if ($requestStart !== null) {
            $processingTimeSeconds = microtime(true) - $requestStart;
        }

        $statusCode = $response->getStatusCode();
        $path = $request->getUri()->getPath();
        $location = $response->getHeader('Location')[0] ?? null;

        $message = $this->formatMessage($statusCode, $path, $location, $processingTimeSeconds ?? null);

        $this->logger->info($message);
    }

    private function formatMessage(int $statusCode, string $path, ?string $location, ?float $processingTimeSeconds): string
    {
        $format = '%s -> %s';
        $args = [
            $this->formatPath($path),
            $this->formatStatusCode($statusCode),
        ];

        if ($location !== null && $statusCode > 299 && $statusCode < 400) {
            $format .= ' -> %s';
            $args[] = $this->formatPath($location);
        }

        if ($processingTimeSeconds !== null) {
            $format .= ' %s';
            $args[] = $this->formatProcessingTime($processingTimeSeconds);
        }

        return sprintf($format, ...$args);
    }

    private function formatPath(string $path): string {
        if (!$this->enhanced) {
            return $path;
        }

        return sprintf('<blue>%s</blue>', $path);
    }

    private function formatStatusCode(int $statusCode): string {
        if (!$this->enhanced) {
            return (string) $statusCode;
        }

        $statusColor = match (true) {
            $statusCode < 300 => 'green',
            $statusCode < 400 => 'yellow',
            default => 'red',
        };

        return sprintf('<%s>%d</%1$s>', $statusColor, $statusCode);
    }

    private function formatProcessingTime(float $processingTimeSeconds): string {
        $unitIdx = 0;
        while ($processingTimeSeconds < 1 && $unitIdx < 3) {
            $processingTimeSeconds *= 1000;
            $unitIdx++;
        }

        $unit = match ($unitIdx) {
            0 => 's',
            1 => 'ms',
            2 => 'µs',
            3 => 'ns',
        };

        $time = sprintf('[%.2f%s]', $processingTimeSeconds, $unit);
        if (!$this->enhanced) {
            return $time;
        }

        return "<gray>$time</gray>";
    }
}
