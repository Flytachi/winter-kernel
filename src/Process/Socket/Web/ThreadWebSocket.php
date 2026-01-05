<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Socket\Web;

use Flytachi\Winter\Kernel\Process\Core\Dispatch;
use Flytachi\Winter\Kernel\Process\Socket\Web\PDU\Msg;
use Flytachi\Winter\Kernel\Process\Socket\Web\PDU\WSResource;
use Flytachi\Winter\Kernel\Process\Traits\ThreadSignalHandler;
use Flytachi\Winter\Thread\ThreadException;

abstract class ThreadWebSocket extends Dispatch
{
    use SocketWebServerHandler;
    use ThreadSignalHandler;

    /**
     * The main loop interval in microseconds.
     * Determines how often the main loop will run even if there is no network activity.
     * Lower values increase CPU usage but make the server more responsive to internal timers.
     * Default is 200,000 microseconds (0.2 seconds), which means max 5 loops per second.
     * @var int
     */
    protected int $loopInterval = 200_000; // 0.2 seconds

    /**
     * The IP address to listen on.
     * '0.0.0.0' means listen on all available network interfaces.
     * '127.0.0.1' means listen only for local connections.
     * @var string
     */
    protected string $ip = '0.0.0.0';

    /**
     * The port to listen on.
     * Must be in the range 1024-65535 unless running as root.
     * @var int
     */
    protected int $port = 9001;

    /**
     * The maximum number of seconds the server should run.
     * 0 means run indefinitely.
     * @var int
     */
    protected int $timeWorkLimit = 0;

    /**
     * The time the server was started.
     * @var int
     */
    protected int $startTime;

    /** @var resource|null */
    protected $resourceConnection;

    /** @var WSResource[] */
    protected array $connects = [];

    protected string $exNamespace = 'web-socket';

    /**
     * Called when a new client has successfully completed the WebSocket handshake.
     * @param WSResource $resource The resource object for the new client.
     */
    abstract protected function handleConnect(WSResource $resource): void;

    /**
     * Called when a message is received from a client.
     * @param WSResource $resource The client's resource object.
     * @param Msg $msg The decoded message.
     */
    abstract protected function handle(WSResource $resource, Msg $msg): void;

    /**
     * Called when a client's connection is closed (either by client or server).
     * @param WSResource $resource The client's resource object.
     */
    abstract protected function handleDisconnect(WSResource $resource): void;

    final protected function resolutionStart(): void
    {
        parent::resolutionStart();
        $this->prepareSignalHandler();
    }

    final public static function dispatch(mixed $data = null): int
    {
        return parent::dispatch($data);
    }

    /**
     * The main entry point for the thread. This method is final and cannot be overridden.
     * It configures and starts the WebSocket server.
     *
     * @param mixed|null $data Data passed from the dispatch() call, expected to be an array with 'ip' and 'port'.
     * @throws ThreadException
     * @internal
     */
    final public function resolution(mixed $data = null): void
    {
        if (is_array($data)) {
            $this->ip = (string) ($data['ip'] ?? $this->ip);
            $this->port = (int) ($data['port'] ?? $this->port);
        }

        $this->logger->debug("Starting the Web Server...[tcp://{$this->ip}:{$this->port}]");

        try {
            $this->resourceConnection = stream_socket_server(
                "tcp://{$this->ip}:{$this->port}",
                $errno,
                $errorStr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
            );
            if (!$this->resourceConnection) {
                throw new ThreadException("Cannot start server: {$errorStr}({$errno})");
            }

            stream_set_blocking($this->resourceConnection, false);

            $this->logger->debug("Server is running. Listening for connections...");
            $this->startTime = time();
            $this->listen();
        } catch (\Throwable $exception) {
            $this->logger->critical($exception->getMessage());
        } finally {
            $this->socketClose();
        }
    }

    final protected function resolutionEnd(): void
    {
        $this->socketClose();
    }

    final protected function disconnectClient(WSResource $resource): void
    {
        try {
            $this->handleDisconnect($resource);
        } catch (\Throwable $exception) {
            $this->logger->critical('handlerDisconnect: ' . $exception->getMessage());
        }

        $frame = WebSocketProtocol::encode(
            'Connection closed',
            'close',
            false,
            1000
        );
        @fwrite($resource->getConnect(), $frame);
        @fclose($resource->getConnect());
        unset($this->connects[(string) $resource]);
        $this->logger->debug("Client disconnected: {$resource}");
    }

    final protected function socketClose(): void
    {
        $connectsToClose = $this->connects;
        foreach ($connectsToClose as $resource) {
            $this->disconnectClient($resource);
        }
        $this->connects = [];

        if (is_resource($this->resourceConnection)) {
            fclose($this->resourceConnection);
            $this->resourceConnection = null;
        }
        $this->logger->debug("All connections closed.");
    }

    private function listen(): void
    {
        while (true) {
            $read = array_map(fn(WSResource $res) => $res->getConnect(), $this->connects);
            $read[] = $this->resourceConnection;

            $write = [];
            foreach ($this->connects as $resource) {
                if (strlen($resource->writeBuffer) > 0) {
                    $write[] = $resource->getConnect();
                }
            }
            $except = null;

            $seconds = intdiv($this->loopInterval, 1_000_000);
            $microseconds = $this->loopInterval % 1_000_000;
            $activity = @stream_select($read, $write, $except, $seconds, $microseconds);
            if ($activity === false) {
                continue;
            }

            if (in_array($this->resourceConnection, $read, true)) {
                if ($newConnection = stream_socket_accept($this->resourceConnection, 0)) {
                    stream_set_blocking($newConnection, false); // Важно!
                    $info = WebSocketProtocol::handshake($newConnection);

                    if ($info !== false) {
                        $resource = new WSResource($newConnection, $info);
                        $this->connects[(string) $newConnection] = $resource;
                        $this->logger->debug("New client connected: {$resource}");
                        try {
                            $this->handleConnect($resource);
                        } catch (\Throwable $exception) {
                            $this->logger->critical('handlerConnect: ' . $exception->getMessage());
                        }
                    }
                }
                unset($read[array_search($this->resourceConnection, $read, true)]);
            }

            foreach ($read as $connect) {
                $resource = $this->connects[(string) $connect];
                $data = @fread($connect, 65535);

                if ($data === false || ($data === '' && feof($connect))) {
                    $this->logger->debug("Client {$resource} has disconnected (EOF).");
                    $this->disconnectClient($resource);
                    continue;
                }

                if ($data === '') {
                    continue;
                }

                $resource->readBuffer .= $data;
                while (strlen($resource->readBuffer) > 0) {
                    $decodedFrame = WebSocketProtocol::decode($resource->readBuffer);
                    if ($decodedFrame === false) {
                        break;
                    }

                    $resource->readBuffer = substr($resource->readBuffer, $decodedFrame->frameLength);
                    $msg = $decodedFrame->msg;

                    if ($msg->type === 'error' || $msg->type === 'close') {
                        if ($msg->type === 'error') {
                            $this->logger->warning("Received '{$msg->type}' frame from {$resource}. Closing connection.");
                        }
                        $this->disconnectClient($resource);
                        break;
                    }

                    try {
                        $this->handle($resource, $msg);
                    } catch (\Throwable $exception) {
                        $this->logger->critical('handler: ' . $exception->getMessage());
                    }
                }
            }

            foreach ($write as $connect) {
                $resource = $this->connects[(string) $connect];
                $bytesWritten = @fwrite($connect, $resource->writeBuffer);
                if ($bytesWritten === false) {
                    $this->disconnectClient($resource);
                    continue;
                }

                if ($bytesWritten === strlen($resource->writeBuffer)) {
                    $resource->writeBuffer = '';
                } else {
                    $resource->writeBuffer = substr($resource->writeBuffer, $bytesWritten);
                }
            }

            if ($this->timeWorkLimit > 0 && (time() - $this->startTime) > $this->timeWorkLimit) {
                $this->logger->notice('Time limit reached. Stopping server.');
                break;
            }

            pcntl_signal_dispatch();
            $this->loop();
        }
    }

    protected function loop(): void
    {
    }

    /**
     * Asynchronously sends a message to a client.
     *
     * This method does not write to the socket directly. Instead, it encodes the
     * payload into a WebSocket frame and appends it to the client's write buffer.
     * The main `listen` loop will then handle the actual non-blocking write.
     *
     * @param WSResource $resource The client resource to send the message to.
     * @param string $payload The message content.
     * @param string $type The WebSocket frame type ('text', 'binary', etc.).
     */
    public function send(WSResource $resource, string $payload, string $type = 'text'): void
    {
        if (!isset($this->connects[(string) $resource])) {
            $this->logger->warning("Attempted to send to a non-existent or closed connection: {$resource}");
            return;
        }

        $frame = WebSocketProtocol::encode($payload, $type);
        $resource->writeBuffer .= $frame;

        $this->logger->debug("Queued " . strlen($frame) . " bytes to send to {$resource}");
    }
}
