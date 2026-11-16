<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App;

/**
 * What a {@see Frontend} runs beside the application: a server of its own — a development
 * server, a rendering server — that the HTTP server forwards the frontend's requests to.
 *
 * The kernel runs it as an outside program under the supervisor, on a loopback port it
 * picks, and tells it where in the environment: `PORT`, `HOST` (`127.0.0.1`) and
 * `WINTER_BACKEND` — the address it reaches the application's controllers at, where no
 * request is ever forwarded back to it. It runs in the project root.
 *
 * @link https://winterframe.net/docs/frontend The frontend in the application
 */
final readonly class FrontProgram
{
    /**
     * @param list<string> $command The program and its arguments.
     * @param array<string, string> $env What it needs in its environment beside the
     *     application's; `PORT`, `HOST` and `WINTER_BACKEND` are the kernel's.
     * @param ProgramOutput|null $output How its output becomes the application's log.
     * @param int $startTimeout Seconds it has to listen on its port; 0 waits for good.
     * @param bool $server Whether it answers every method itself (it renders pages and
     *     has routes of its own): every request no controller took goes to it. False — it
     *     serves pages and files: a GET or HEAD only, the rest is the controllers' 404.
     * @throws \InvalidArgumentException A command or a variable exec cannot take.
     */
    public function __construct(
        public array $command,
        public array $env = [],
        public ?ProgramOutput $output = null,
        public int $startTimeout = 60,
        public bool $server = false,
    ) {
        $strings = array_filter($command, static fn($part): bool => is_string($part));
        if ($command === [] || !array_is_list($command) || count($strings) !== count($command) || $command[0] === '') {
            throw new \InvalidArgumentException(
                'a command is a non-empty list of strings: the program, then its arguments.'
            );
        }
        foreach ($env as $name => $value) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $name) !== 1) {
                throw new \InvalidArgumentException("not a variable name: \"{$name}\".");
            }
            if (!is_string($value) || str_contains($value, "\n") || str_contains($value, "\0")) {
                throw new \InvalidArgumentException("variable {$name}: a value is one line of text.");
            }
        }
        if ($startTimeout < 0) {
            throw new \InvalidArgumentException('startTimeout cannot be negative.');
        }
    }
}
