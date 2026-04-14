<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Factory\Middleware\Cors;

trait AccessControlTrait
{
    private function useHeaders(): void
    {
        header_remove("X-Powered-By");
        if (!empty($this->origin)) {
            if (count($this->origin) == 1) {
                header('Access-Control-Allow-Origin: ' . $this->origin[0]);
            } elseif (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $this->origin)) {
                header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
            }
        } else {
            header('Access-Control-Allow-Origin: *');
        }
        if (!empty($this->allowHeaders)) {
            header('Access-Control-Allow-Headers: ' . implode(', ', $this->allowHeaders));
        }
        if (!empty($this->exposeHeaders)) {
            header('Access-Control-Expose-Headers: ' . implode(', ', $this->exposeHeaders));
        }
        if ($this->credentials && !empty($this->origin)) {
            header('Access-Control-Allow-Credentials: true');
        }
        if ($this->maxAge > 0) {
            header('Access-Control-Max-Age: ' . $this->maxAge);
        }
        if (!empty($this->vary)) {
            header('Vary: ' . implode(', ', $this->vary));
        }
    }
}
