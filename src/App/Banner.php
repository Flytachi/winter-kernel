<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App;

use Composer\InstalledVersions;

/**
 * The Winter startup banner — a Spring-Boot-style splash printed to the terminal
 * when the application comes up ({@see \Flytachi\Winter\Kernel\WinterApplication::serve()}).
 *
 * It is human-facing: it writes ANSI to STDOUT and is shown only on an interactive
 * terminal. The structured "application up" line still goes to the log channel, so
 * nothing is lost when the banner is suppressed (piped output, `--no-banner`, or
 * `WINTER_BANNER=off`).
 */
final class Banner
{
    private const string PKG = 'flytachi/winter-kernel';

    /** The "Winter" wordmark. */
    private const array MARK = [
        '██     ██ ██ ███    ██ ████████ ███████ ██████ ',
        '██     ██ ██ ████   ██    ██    ██      ██   ██',
        '██  █  ██ ██ ██ ██  ██    ██    █████   ██████ ',
        '██ ███ ██ ██ ██  ██ ██    ██    ██      ██   ██',
        ' ███ ███  ██ ██   ████    ██    ███████ ██   ██',
    ];

    // Winter palette.
    private const string RESET = "\033[0m";
    private const string MARK_COLOR = "\033[96m";   // bright cyan — wordmark
    private const string VALUE = "\033[36m";        // cyan — values
    private const string LABEL = "\033[2;36m";      // dim cyan — labels / snowflakes
    private const string META = "\033[2;37m";       // grey — version / meta
    private const string OK = "\033[32m";           // green — up
    private const string BOLD = "\033[1;97m";       // bold white

    /**
     * Prints the banner to STDOUT.
     *
     * @param list<array{string, string}> $rows Ordered label/value lines (web, daemon, …).
     * @param float $elapsedMs Milliseconds from boot start to now.
     */
    public static function print(array $rows, float $elapsedMs): void
    {
        echo self::render($rows, $elapsedMs);
    }

    /**
     * @param list<array{string, string}> $rows Ordered label/value lines.
     * @param float $elapsedMs Milliseconds from boot start to now.
     */
    public static function render(array $rows, float $elapsedMs): string
    {
        $last = count(self::MARK) - 1;
        $out  = "\n";
        foreach (self::MARK as $i => $line) {
            $flake = ($i === 0 || $i === $last) ? '❄' : ' ';
            $out  .= '  ' . self::LABEL . $flake . self::RESET
                  . ' ' . self::MARK_COLOR . $line . self::RESET . "\n";
        }
        $out .= "\n";

        $out .= '   ' . self::META . ':: ' . self::RESET
              . self::VALUE . 'winter-kernel' . self::RESET
              . self::META . ' ::' . self::RESET
              . '   ' . self::META . '(v' . self::version() . ')' . self::RESET
              . '   ' . self::LABEL . implode(' · ', self::metaTail()) . self::RESET . "\n";

        $out .= '   ' . self::LABEL . str_repeat('─', 53) . self::RESET . "\n";
        foreach ($rows as [$label, $value]) {
            $out .= sprintf(
                "   %s%-11s%s %s%s%s\n",
                self::LABEL,
                $label,
                self::RESET,
                self::VALUE,
                $value,
                self::RESET,
            );
        }
        $out .= '   ' . self::LABEL . str_repeat('─', 53) . self::RESET . "\n";

        $out .= '   ' . self::OK . '✔' . self::RESET
              . ' ' . self::BOLD . 'Application up' . self::RESET
              . self::META . ' in ' . self::elapsed($elapsedMs) . self::RESET . "\n\n";

        return $out;
    }

    /**
     * True unless the banner is suppressed by `--no-banner`, `WINTER_BANNER=off`, or a
     * non-interactive STDOUT (piped output, a service manager, a log file).
     */
    public static function isEnabled(ApplicationArguments $args): bool
    {
        if ($args->has('no-banner')) {
            return false;
        }

        $flag = env('WINTER_BANNER');
        if ($flag !== null && in_array(strtolower((string) $flag), ['off', '0', 'false', 'no'], true)) {
            return false;
        }

        return defined('STDOUT') && stream_isatty(STDOUT);
    }

    /**
     * @return list<string> Runtime / PHP / PID parts of the meta line.
     */
    private static function metaTail(): array
    {
        $tail = [];
        if (extension_loaded('swoole')) {
            $tail[] = 'Swoole ' . (defined('SWOOLE_VERSION') ? SWOOLE_VERSION : (phpversion('swoole') ?: '?'));
        }
        $tail[] = 'PHP ' . PHP_VERSION;
        $tail[] = 'PID ' . getmypid();

        return $tail;
    }

    private static function version(): string
    {
        try {
            if (InstalledVersions::isInstalled(self::PKG)) {
                return InstalledVersions::getPrettyVersion(self::PKG) ?? 'dev';
            }
        } catch (\Throwable) {
            // Fall through to 'dev' when Composer runtime metadata is unavailable.
        }

        return 'dev';
    }

    private static function elapsed(float $ms): string
    {
        return $ms < 1000.0
            ? (int) round($ms) . ' ms'
            : round($ms / 1000, 2) . ' s';
    }
}
