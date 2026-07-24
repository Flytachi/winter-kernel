<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Old\Process\Socket\Web;

trait SocketWebServerHandler
{
    private function signInterrupt(): never
    {
        $this->resolutionEnd();
        $this->asInterrupt();
        exit();
    }

    private function signTermination(): never
    {
        $this->resolutionEnd();
        $this->asTermination();
        exit(1);
    }

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
