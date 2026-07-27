<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\App\Config;

/**
 * Logging configuration contract — declares extra log channels in code (the rare
 * case; most channels are pure .env). Any implementing class is discovered on
 * scan and invoked at boot.
 *
 * ```
 * final class LoggingConfig implements LoggingConfigurer
 * {
 *     public function configureChannels(ChannelRegistry $channels): void
 *     {
 *         $channels->add('job')->add('audit');
 *     }
 * }
 * ```
 */
interface LoggingConfigurer
{
    public function configureChannels(ChannelRegistry $channels): void;
}
