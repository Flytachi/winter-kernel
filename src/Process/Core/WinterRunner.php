<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Process\Core;

use Flytachi\Winter\Logger\LoggerFactory;
use Flytachi\Winter\Thread\Runnable;
use Flytachi\Winter\Thread\Runner\AdaptiveRunner;
use Flytachi\Winter\Thread\ThreadException;
use Opis\Closure\Security\DefaultSecurityProvider;

final readonly class WinterRunner extends AdaptiveRunner
{
    public static function adaptive(): static
    {
        return new WinterRunner(new DefaultSecurityProvider(secret: env('WINTER_KEY')));
    }

    public function execute(array $options): int
    {
        $logger = LoggerFactory::getLogger('Runner');

        if (isset($options['debug'])) {
            error_reporting(E_ALL);
            ini_set('display_errors', 1);
            ini_set('display_startup_errors', 1);
        } else {
            error_reporting(0);
            ini_set('display_errors', 0);
            ini_set('display_startup_errors', 0);
        }

        try {
            $payload = $this->receive($options);
        } catch (\Throwable $e) {
            $logger->alert($e->getMessage());
            return 1;
        }
        if ($payload === '') {
            $logger->alert('No payload received.');
            return 1;
        }

        try {
            $runnable = \Opis\Closure\unserialize($payload, $this->security);
        } catch (\Throwable $e) {
            $logger->alert('Failed to deserialize payload: ' . $e->getMessage());
            return 1;
        }

        unset($payload);

        if (!$runnable instanceof Runnable) {
            $logger->critical('The provided payload is not a valid Runnable object.');
            return 1;
        }

        if (isset($options['detach'])) {
            try {
                $this->daemonize();
            } catch (ThreadException $e) {
                $logger->alert($e->getMessage());
                return 1;
            }
        }

        $this->setProcessTitle($options, $runnable);

        try {
            $runnable->run($this->parseArgs());
            return 0;
        } catch (\Throwable $e) {
            $logger->critical('Uncaught exception in background process: ' . $e->getMessage());
            if (env('DEBUG' , false)) {
                $logger->critical($e->getTraceAsString());
            }
            return 1;
        }
    }
}