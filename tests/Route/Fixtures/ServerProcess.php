<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Route\Fixtures;

/**
 * Runs the fixture application as a real server in its own process.
 *
 * Shared by the tests that need an actual socket: one asks it questions over HTTP, the
 * other watches how it dies. Everything that makes that reproducible lives here — an
 * unused port so parallel runs never collide, a runner written the way a project writes
 * its entry file, a private storage directory, and captured output.
 */
final class ServerProcess
{
    private int $port = 0;
    private int $pid = 0;
    private string $storage = '';
    private string $runner = '';
    private string $logFile = '';

    public function port(): int
    {
        return $this->port;
    }

    public function log(): string
    {
        return (string) @file_get_contents($this->logFile);
    }

    public function url(string $path): string
    {
        return 'http://127.0.0.1:' . $this->port . $path;
    }

    /** Boots the server and returns once it accepts connections, or false on timeout. */
    public function start(float $timeout = 15.0): bool
    {
        $this->port    = self::freePort();
        $this->storage = sys_get_temp_dir() . '/wk_serve_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $this->runner  = $this->storage . '.php';
        $this->logFile = $this->storage . '.log';

        // The entry a project would write by hand: load the autoloader, run the app.
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        file_put_contents($this->runner, sprintf(
            "<?php\nrequire %s;\n\\Flytachi\\Winter\\Kernel\\Tests\\Route\\Fixtures\\App\\ServeApp::main("
            . "['call', 'run', '--host=127.0.0.1', '--port=%d']);\n",
            var_export($autoload, true),
            $this->port,
        ));

        $this->pid = (int) trim((string) shell_exec(sprintf(
            'WK_SERVE_STORAGE=%s %s %s >> %s 2>&1 & echo $!',
            escapeshellarg($this->storage),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->runner),
            escapeshellarg($this->logFile),
        )));

        return $this->awaitReady($timeout);
    }

    public function signal(int $signal): void
    {
        if ($this->pid > 0) {
            @posix_kill($this->pid, $signal);
        }
    }

    public function isAlive(): bool
    {
        return $this->pid > 0 && @posix_getpgid($this->pid) !== false;
    }

    /** Waits for the process to leave on its own; false when it outstays the timeout. */
    public function awaitExit(float $timeout): bool
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if (!$this->isAlive()) {
                return true;
            }
            usleep(100_000);
        }

        return false;
    }

    /** Stops the server if it is still up, then removes everything it left behind. */
    public function stop(): void
    {
        if ($this->pid > 0) {
            @exec(sprintf('kill -TERM %d 2>/dev/null', $this->pid));
            $this->awaitExit(4.0);
            @exec(sprintf('kill -KILL %d 2>/dev/null', $this->pid));
            $this->pid = 0;
        }

        @unlink($this->runner);
        @unlink($this->logFile);
        self::removeTree($this->storage);
    }

    /** The storage tree nests (storage/runnable/<name>/…), so it has to go depth-first. */
    private static function removeTree(string $path): void
    {
        if ($path === '' || !is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }

    /** @return array{status: int, body: string, headers: list<string>} */
    public function request(string $method, string $path): array
    {
        $context = stream_context_create(['http' => [
            'method'        => $method,
            'timeout'       => 5,
            'ignore_errors' => true, // 4xx/5xx must come back as a response, not a warning
        ]]);

        $body    = @file_get_contents($this->url($path), false, $context);
        $headers = $http_response_header ?? [];

        return [
            'status'  => self::statusOf($headers),
            'body'    => $body === false ? '' : $body,
            'headers' => $headers,
        ];
    }

    /** @param list<string> $headers */
    public static function statusOf(array $headers): int
    {
        foreach ($headers as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                return (int) $m[1];
            }
        }

        return 0;
    }

    /** @param list<string> $headers */
    public static function headerOf(array $headers, string $name): string
    {
        foreach ($headers as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2 && strcasecmp(trim($parts[0]), $name) === 0) {
                return trim($parts[1]);
            }
        }

        return '';
    }

    private function awaitReady(float $timeout): bool
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.3);
            if ($socket !== false) {
                fclose($socket);
                return true;
            }
            usleep(200_000);
        }

        return false;
    }

    /** Asks the OS for an unused port, so parallel runs do not collide. */
    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name   = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }
}
