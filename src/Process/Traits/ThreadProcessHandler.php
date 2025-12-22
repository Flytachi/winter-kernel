<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Traits;

trait ThreadProcessHandler
{
    /**
     * Sends an interrupt signal to all child processes and terminates the current process.
     *
     * @return never This method does not return any value.
     */
    private function signInterrupt(): never
    {
        if (!$this->iAmChild) {
            // Parent
            foreach ($this->childrenPid as $childPid) {
                posix_kill($childPid, SIGINT);
                pcntl_waitpid($childPid, $status);
            }
            $this->resolutionEnd();
            $this->asInterrupt();
        } else {
            // Child
            $this->asChildInterrupt();
        }
        exit();
    }

    /**
     * Signs termination for the current process.
     *
     * If the current process is a parent process, it sends the termination signal (SIGTERM) to all its child processes,
     * waits for them to exit, and then performs necessary termination steps for the parent process: calls
     * `asTermination()` and `endRun()`. If the current process is a child process,
     * it calls `asProcTermination()` before terminating itself with status code 1 (EXIT_FAILURE).
     *
     * @return never This function does not return any value as it terminates the process.
     */
    private function signTermination(): never
    {
        if (!$this->iAmChild) {
            // Parent
            foreach ($this->childrenPid as $childPid) {
                posix_kill($childPid, SIGTERM);
                pcntl_waitpid($childPid, $status);
            }
            $this->resolutionEnd();
            $this->asTermination();
        } else {
            // Child
            $this->asChildTermination();
        }
        exit(1);
    }

    /**
     * Closes the sign process and its children processes.
     *
     * If the current process is the parent, it will send a SIGHUP signal to each child process,
     * wait for them to exit, and then perform cleanup operations for the sign process.
     * If the current process is a child, it will perform cleanup operations specifically for child processes.
     * This method never returns as it exits the process after performing the necessary operations.
     *
     * @return never This method does not return.
     */
    private function signClose(): never
    {
        if (!$this->iAmChild) {
            // Parent
            foreach ($this->childrenPid as $childPid) {
                posix_kill($childPid, SIGHUP);
                pcntl_waitpid($childPid, $status);
            }
            $this->resolutionEnd();
            $this->asClose();
        } else {
            // Child
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
