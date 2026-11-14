<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App;

/**
 * How the output of an outside program becomes the application's log — the `output` of
 * {@see Component::command()}. Without one, the program writes straight to the
 * application's stdout and stderr, as it is.
 *
 * With one, the program's stdout and stderr go to the application instead, line by
 * line: each line is handed to {@see line()}, and what it says is logged with the
 * application's handlers — the channel is the component's name, `LOG_LEVEL` applies, and
 * the line or JSON format is the application's own. A line that starts an entry is
 * logged once the next entry starts, or the program has been quiet for a moment: the
 * lines that continue it ({@see OutputLine::more()}) join it first.
 *
 * It is asked from the application's own process, for every line: it must be quick, must
 * not throw, and keeps no state — the same line always reads the same.
 *
 * @link https://winterframe.net/docs/components#output The output of a program
 */
interface ProgramOutput
{
    /**
     * What one line the program wrote means.
     *
     * @param string $line The line, without its newline.
     */
    public function line(string $line): OutputLine;
}
