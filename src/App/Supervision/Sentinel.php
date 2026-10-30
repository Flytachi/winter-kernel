<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Supervision;

use Flytachi\Winter\Kernel\App\Runtime\ApplicationRuntime;

/**
 * Stops the components when the application dies without stopping them.
 *
 * Every component runs in a process group of its own, so the components outlive an
 * application killed outright — `kill -9`, the OOM killer: an HTTP server orphaned that
 * way kept its port, and the next `call run` could not start. In a container that
 * cannot happen (the container goes with its PID 1); on a host it can, and nothing in
 * PHP asks the kernel to end a child with its parent (`PR_SET_PDEATHSIG` is Linux-only
 * and out of reach anyway).
 *
 * So the application forks this small process first. The supervisor tells it the group
 * of every component it starts, and that the group is gone when it ends. The link
 * between them closes when the application dies, however it dies: without a `done`
 * before it, the sentinel sends SIGTERM to every group it knows, SIGKILL to what is left
 * after {@see GRACE} seconds, and exits. After a normal stop the supervisor has already
 * stopped everything and says `done`.
 *
 * The sentinel ignores the stop signals — Ctrl+C in a terminal reaches the foreground
 * group, and it must not go before the application does — and holds no lock.
 *
 * @internal Started by {@see Supervisor::run()}.
 */
final class Sentinel
{
    /** Seconds the components get to stop before SIGKILL. */
    private const float GRACE = 10.0;
    /** The signals the sentinel ignores. */
    private const array SIGNALS = [SIGTERM, SIGINT, SIGHUP, SIGUSR1, SIGUSR2];

    /**
     * @param resource $link The application's end of the link.
     */
    private function __construct(private mixed $link, public readonly int $pid)
    {
    }

    /**
     * Forks the sentinel. Null when it cannot be started — the application then runs as
     * before, without the cover.
     */
    public static function spawn(): ?self
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            return null;
        }
        // A plain socket, not Swoole's: one made while the runtime hooks are on stays a
        // coroutine socket in the sentinel too, and reading it outside a coroutine is a
        // fatal error there.
        $hooks = extension_loaded('swoole') ? \Swoole\Runtime::getHookFlags() : 0;
        if ($hooks !== 0) {
            \Swoole\Runtime::enableCoroutine(0);
        }
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($hooks !== 0) {
            \Swoole\Runtime::enableCoroutine($hooks);
        }
        if ($pair === false) {
            return null;
        }
        $application = getmypid();
        // A stop signal between the fork and the sentinel's SIG_IGN would end it by
        // default: held back until it has turned them off.
        pcntl_sigprocmask(SIG_BLOCK, self::SIGNALS, $mask);
        $pid = pcntl_fork();
        if ($pid === -1) {
            pcntl_sigprocmask(SIG_SETMASK, $mask);
            fclose($pair[0]);
            fclose($pair[1]);
            return null;
        }
        if ($pid === 0) {
            fclose($pair[0]);
            self::watch($pair[1], $application, $mask);
        }
        pcntl_sigprocmask(SIG_SETMASK, $mask);
        fclose($pair[1]);
        return new self($pair[0], $pid);
    }

    /** A component started: its process group. */
    public function track(int $group): void
    {
        $this->send("+{$group}\n");
    }

    /** A component ended: its group is no longer the sentinel's business. */
    public function untrack(int $group): void
    {
        $this->send("-{$group}\n");
    }

    /**
     * In a process forked from the application: closes the inherited end of the link.
     * A component that kept it would keep the link open after the application died, and
     * the sentinel would never notice.
     */
    public function forget(): void
    {
        if ($this->link !== null) {
            @fclose($this->link);
            $this->link = null;
        }
    }

    /** The application stopped its components itself: the sentinel exits. */
    public function done(): void
    {
        $this->send("done\n");
        if ($this->link !== null) {
            @fclose($this->link);
            $this->link = null;
        }
    }

    private function send(string $line): void
    {
        // A sentinel that is gone cannot be told anything; the application goes on — even
        // when an error handler turns the broken pipe's warning into an exception.
        if ($this->link !== null) {
            try {
                @fwrite($this->link, $line);
            } catch (\Throwable) {
                $this->link = null;
            }
        }
    }

    /**
     * The sentinel's side: waits for the link to close. Never returns.
     *
     * @param resource $link
     * @param array<int> $mask The signal mask from before the fork.
     */
    private static function watch(mixed $link, int $application, array $mask): never
    {
        @cli_set_process_title('winter-sentinel');
        // Out of the terminal's foreground group, and deaf to the stop signals: it goes
        // after the application, never before it.
        @posix_setpgid(0, 0);
        foreach (self::SIGNALS as $signal) {
            pcntl_signal($signal, SIG_IGN);
        }
        pcntl_sigprocmask(SIG_SETMASK, $mask);
        ApplicationRuntime::dropInheritedLock();
        // Plain blocking I/O, whatever the application set: under Swoole's runtime hooks
        // stream_select() outside a coroutine is a fatal error.
        if (extension_loaded('swoole') && \Swoole\Runtime::getHookFlags() !== 0) {
            \Swoole\Runtime::enableCoroutine(0);
        }

        $groups = [];
        $buffer = '';
        $done = false;
        while (true) {
            // Waits with no time limit. A blocking fread() gives up after
            // `default_socket_timeout` (60 s) and returns false — taken for the end of
            // the link, that stopped a live application's components a minute after
            // its last start. Only the link's real end (EOF) means the application is gone.
            $read = [$link];
            $write = $except = null;
            if (@stream_select($read, $write, $except, null) === false) {
                if (feof($link)) {
                    break;
                }
                // Not to spin if select keeps failing.
                usleep(100_000);
                continue;
            }
            $chunk = @fread($link, 8192);
            if ($chunk === false || $chunk === '') {
                if (feof($link)) {
                    break;
                }
                continue;
            }
            $buffer .= $chunk;
            while (($eol = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $eol);
                $buffer = substr($buffer, $eol + 1);
                if ($line === 'done') {
                    $done = true;
                } elseif ($line !== '' && ($line[0] === '+' || $line[0] === '-')) {
                    $group = (int) substr($line, 1);
                    if ($group > 1) {
                        if ($line[0] === '+') {
                            $groups[$group] = true;
                        } else {
                            unset($groups[$group]);
                        }
                    }
                }
            }
        }

        if (!$done && $groups !== []) {
            @fwrite(STDERR, "winter: the application (PID {$application}) is gone — stopping its components\n");
            self::stop(array_keys($groups));
        }
        self::vanish();
    }

    /**
     * Ends the sentinel without PHP's shutdown. exit() would run every shutdown
     * function and destructor it inherited from the application — a log buffer flushed
     * twice, an error tracker's second flush, a shared resource closed under the
     * application. Its exit status is read by nobody.
     */
    private static function vanish(): never
    {
        @pcntl_exec('/bin/sh', ['-c', 'exit 0']);
        // Only when the image could not be replaced.
        @posix_kill(getmypid(), SIGKILL);
        throw new \LogicException('unreachable');
    }

    /**
     * SIGTERM to every group, SIGKILL to the groups still there after the grace.
     *
     * @param list<int> $groups
     */
    private static function stop(array $groups): void
    {
        foreach ($groups as $group) {
            @posix_kill(-$group, SIGTERM);
        }
        $deadline = microtime(true) + self::GRACE;
        while ($groups !== [] && microtime(true) < $deadline) {
            usleep(100_000);
            $groups = array_values(array_filter($groups, static fn(int $g): bool => @posix_kill(-$g, 0)));
        }
        foreach ($groups as $group) {
            @posix_kill(-$group, SIGKILL);
        }
    }
}
