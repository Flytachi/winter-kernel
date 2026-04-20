<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Process\Traits;

trait ThreadProcessHandler
{
    private function signInterrupt(): never
    {
        if (!$this->iAmChild) {
            foreach ($this->childrenPids as $childPid) {
                posix_kill($childPid, SIGINT);
                pcntl_waitpid($childPid, $status);
            }
            $this->resolutionEnd();
            $this->asInterrupt();
        } else {
            $this->asChildInterrupt();
        }
        exit();
    }

    private function signTermination(): never
    {
        if (!$this->iAmChild) {
            foreach ($this->childrenPids as $childPid) {
                posix_kill($childPid, SIGTERM);
                pcntl_waitpid($childPid, $status);
            }
            $this->resolutionEnd();
            $this->asTermination();
        } else {
            $this->asChildTermination();
        }
        exit(1);
    }

    private function signClose(): never
    {
        if (!$this->iAmChild) {
            foreach ($this->childrenPids as $childPid) {
                posix_kill($childPid, SIGHUP);
                pcntl_waitpid($childPid, $status);
            }
            $this->resolutionEnd();
            $this->asClose();
        } else {
            $this->asChildClose();
        }
        exit(1);
    }

    protected function asInterrupt(): void
    {
        $this->logger->notice("INTERRUPTED");
    }

    protected function asTermination(): void
    {
        $this->logger->warning("TERMINATION");
    }

    protected function asClose(): void
    {
        $this->logger->notice("CLOSE");
    }

    protected function asChildInterrupt(): void
    {
        $this->logger->notice("INTERRUPTED CHILD");
    }

    protected function asChildTermination(): void
    {
        $this->logger->warning("TERMINATION CHILD");
    }

    protected function asChildClose(): void
    {
        $this->logger->notice("CLOSE CHILD");
    }
}
