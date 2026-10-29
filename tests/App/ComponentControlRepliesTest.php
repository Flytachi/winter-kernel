<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use Flytachi\Winter\Kernel\App\Supervision\ComponentControl;
use Flytachi\Winter\Kernel\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * The supervisor's answers to `dispatch()`. One the caller stopped waiting for — it waits
 * five seconds — was never read, so never deleted, and stayed in `.winter/runnable` for
 * good. Answers now expire, and each new one sweeps the expired away.
 */
final class ComponentControlRepliesTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wk_replies_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        Kernel::init(pathRoot: $this->root);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function test_an_answer_nobody_took_is_swept_by_the_next(): void
    {
        $replies = Kernel::runnable('replies', isHash: false);
        $replies->write('forgotten', ['pid' => 1, 'error' => '', 'at' => time() - 120], time() - 60);

        ComponentControl::reply('fresh', 4242);

        self::assertSame(['fresh'], $replies->keys());
        self::assertSame(['pid' => 4242, 'error' => ''], ComponentControl::awaitReply('fresh', 1.0));
        self::assertSame([], $replies->keys(), 'a taken answer is removed');
    }
}
