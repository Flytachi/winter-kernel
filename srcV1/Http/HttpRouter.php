<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http;

use Flytachi\Winter\Base\Header;
use Flytachi\Winter\Base\Interface\Stereotype;
use JetBrains\PhpStorm\Deprecated;

#[Deprecated]
abstract class HttpRouter extends Stereotype
{
    private bool $initHeader = false;

    public function __construct()
    {
        parent::__construct();
    }

    final protected function initHeader(): void
    {
        if ($this->initHeader) {
            return;
        }
        Header::setHeaders();
        $this->initHeader = true;
    }

    abstract protected function route(array $input, bool $isDevelop = false): void;

    final public function run(): void
    {
        if (!isset($_SERVER['REQUEST_URI'])) {
            return;
        }
        $this->initHeader();
        $input = parseUrlDetail($_SERVER['REQUEST_URI']);
        $this->logger->debug(Header::getIpAddress()
            . ' - ' . $_SERVER['REQUEST_METHOD']
            . ' ' . $_SERVER['REQUEST_URI']);
        $this->route($input, (bool) env('DEBUG', false));
    }
}
