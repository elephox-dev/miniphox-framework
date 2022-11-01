<?php
declare(strict_types=1);

namespace Elephox\Miniphox;

use Elephox\DI\Contract\ServiceCollection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

class RequestLoggerMiddleware {
    public function __construct(
        private readonly ServiceCollection $services,
    )
    {
    }

    private function getLogger(): LoggerInterface {
        return $this->services->requireService(LoggerInterface::class);
    }

    public function __invoke(ServerRequestInterface $request, $next): ResponseInterface
    {
        /** @var ResponseInterface $response */
        $response = $next($request);

        $this->logRequest($request, $response);

        return $response;
    }

    private function logRequest(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $statusCode = $response->getStatusCode();
        $path = $request->getUri()->getPath();
        $location = $response->getHeader('Location')[0] ?? null;
        $requestStart = $request->getServerParams()['REQUEST_TIME_FLOAT'] ?? null;
        if ($requestStart !== null) {
            $processingTimeSeconds = microtime(true) - $requestStart;
        }

        $message = $this->formatMessage($statusCode, $path, $location, $processingTimeSeconds ?? null);

        $this->getLogger()->info($message);
    }

    private function formatMessage(int $statusCode, string $path, ?string $location, ?float $processingTimeSeconds): string
    {
        $format = '%s -> %s';
        $args = [
            $this->formatPath($path),
            $this->formatStatusCode($statusCode),
        ];

        if ($statusCode > 299 && $statusCode < 400) {
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
        return sprintf('<blue>%s</blue>', $path);
    }

    private function formatStatusCode(int $statusCode): string {
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

        return sprintf('<gray>[%.2f%s]</gray>', $processingTimeSeconds, $unit);
    }
}
