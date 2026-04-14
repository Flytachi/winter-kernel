<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Traits;

trait ThreadJobHandler
{
    /**
     * Signs an interrupt and terminates the execution of the current script.
     *
     * This method is used to handle interruptions in the code execution flow.
     * It performs the necessary actions to process an interrupt and then terminates
     * the script execution.
     *
     * @return never This method does not return any value.
     */
    private function signInterrupt(): never
    {
        $this->resolutionEnd();
        $this->asInterrupt();
        exit();
    }

    /**
     * Signs the termination of the program.
     *
     * This method sets the program state as "termination" and ends the program execution.
     * It also sets the exit code to 1.
     *
     * @return never
     */
    private function signTermination(): never
    {
        $this->resolutionEnd();
        $this->asTermination();
        exit(1);
    }

    /**
     * Closes the sign and ends the run.
     *
     * @return never
     */
    private function signClose(): never
    {
        $this->resolutionEnd();
        $this->asClose();
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
}
