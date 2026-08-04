<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use Flytachi\Winter\Kernel\App\ApplicationArguments;
use Flytachi\Winter\Kernel\WinterApplication;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Where the server binds: the flag wins, then `.env`, then the built-in default.
 *
 * Every other server setting — workers, tasks, max_request — already comes from `.env`,
 * and host and port were the exception: they could only be given on the command line.
 * That is backwards, since the port is the one most likely to differ per environment, and
 * it forced a Docker setup to repeat the number in the compose file, the Dockerfile and
 * the run command with nothing keeping them in agreement.
 *
 * The flag still wins, because a one-off `--port` is an override by intent.
 */
final class ServerBindingTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->envBackup = $_ENV;
        unset($_ENV['SERVER_HOST'], $_ENV['SERVER_PORT']);
    }

    protected function tearDown(): void
    {
        $_ENV = $this->envBackup;
    }

    /** @param list<string> $argv */
    private function bind(array $argv): array
    {
        $settings = new ReflectionMethod(WinterApplication::class, 'buildServerSettings')
            ->invoke(null, ApplicationArguments::parse(['call', ...$argv]));

        return [$settings->getHost(), $settings->getPort()];
    }

    public function test_it_falls_back_to_the_built_in_default(): void
    {
        self::assertSame(['0.0.0.0', 8000], $this->bind(['run']));
    }

    public function test_the_environment_supplies_the_port(): void
    {
        $_ENV['SERVER_PORT'] = '8003';

        self::assertSame(['0.0.0.0', 8003], $this->bind(['run']));
    }

    public function test_the_environment_supplies_the_host(): void
    {
        $_ENV['SERVER_HOST'] = '127.0.0.1';

        self::assertSame(['127.0.0.1', 8000], $this->bind(['run']));
    }

    public function test_the_flag_wins_over_the_environment(): void
    {
        $_ENV['SERVER_HOST'] = '127.0.0.1';
        $_ENV['SERVER_PORT'] = '8003';

        self::assertSame(['0.0.0.0', 9501], $this->bind(['run', '--host=0.0.0.0', '--port=9501']));
    }

    /**
     * One of the two may be overridden without disturbing the other.
     */
    public function test_the_flag_overrides_only_what_it_names(): void
    {
        $_ENV['SERVER_HOST'] = '127.0.0.1';
        $_ENV['SERVER_PORT'] = '8003';

        self::assertSame(['127.0.0.1', 9501], $this->bind(['run', '--port=9501']));
    }

    /**
     * `env()` turns a numeric string into an int on its own; the port must survive that
     * without being re-parsed into something else.
     */
    public function test_a_numeric_environment_value_is_read_as_a_port(): void
    {
        $_ENV['SERVER_PORT'] = 8003;

        self::assertSame(8003, $this->bind(['run'])[1]);
    }
}
