<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures\Supervision;

/** Cannot be built while the file named by WK_BROKEN exists — a fault that can be fixed. */
final class FlaggedExplosive
{
    public function __construct()
    {
        $flag = getenv('WK_BROKEN');
        if ($flag !== false && $flag !== '' && is_file($flag)) {
            throw new \RuntimeException('broken until fixed');
        }
    }
}
