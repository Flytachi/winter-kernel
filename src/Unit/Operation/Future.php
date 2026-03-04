<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Unit\Operation;

use Flytachi\Winter\Kernel\Exception\Error;
use Flytachi\Winter\Thread\Thread;

readonly class Future
{
    private OpResult $result;

    public function __construct(
        private string $id,
        private Thread $thread
    ) {
        Operation::store()->write($this->id, 'pending');
        $thread->start();
    }

    public function join(): void
    {
        $this->thread->join();
        usleep(1_000); // 0.001 seconds
    }

    public function get(): OpResult
    {
        if (!isset($this->result)) {
            $this->join();

            for ($i = 0; $i < 3; $i++) {
                $opResult = Operation::store()->read($this->id);
                if ($opResult) {
                    $this->result = $opResult;
                    Operation::store()->del($this->id);
                    break;
                }
                if ($i == 2) {
                    Error::throw('Operation result not found');
                }
                usleep(1_000);
            }
        }

        return $this->result;
    }

    public function return(bool $isThrow = true): mixed
    {
        $opResult = $this->get();
        if ($isThrow && $opResult->getThrowable()) {
            throw $opResult->getThrowable();
        }
        return $opResult->getResult();
    }

    public function __destruct()
    {
        Operation::store()->del($this->id);
    }
}
