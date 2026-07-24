<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Old\Process\Socket\Web;

use Flytachi\Winter\K2\Old\Process\Core\Dispatch;
use Flytachi\Winter\K2\Old\Process\Socket\Web\PDU\Msg;
use Flytachi\Winter\K2\Old\Process\Socket\Web\PDU\WSResource;
use Flytachi\Winter\K2\Old\Process\Traits\ThreadSignalHandler;
use Flytachi\Winter\Thread\ThreadException;

abstract class ThreadWebSocket extends Dispatch
{
    use SocketWebServerHandler;
    use ThreadSignalHandler;

    protected int $loopInterval  = 200_000;
    protected string $ip         = '0.0.0.0';
    protected int $port          = 9001;
    protected int $timeWorkLimit = 0;
    protected int $startTime;

    /** @var resource|null */
    protected $resourceConnection;

    /** @var WSResource[] */
    protected array $connects = [];

    protected string $exNamespace = 'web-socket';

    abstract protected function handleConnect(WSResource $resource): void;
    abstract protected function handle(WSResource $resource, Msg $msg): void;
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

    /** @throws ThreadException */
    final public function resolution(mixed $data = null): void
    {
        if (is_array($data)) {
            $this->ip   = (string) ($data['ip']   ?? $this->ip);
            $this->port = (int)   ($data['port']  ?? $this->port);
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
            $this->logger->error('handlerDisconnect: ' . $exception->getMessage());
        }

        @fwrite($resource->getConnect(), WebSocketProtocol::encode('Connection closed', 'close', false, 1000));
        @fclose($resource->getConnect());
        unset($this->connects[(string) $resource]);
        $this->logger->debug("Client disconnected: {$resource}");
    }

    final protected function socketClose(): void
    {
        foreach ($this->connects as $resource) {
            $this->disconnectClient($resource);
        }
        $this->connects = [];

        if (is_resource($this->resourceConnection)) {
            fclose($this->resourceConnection);
            $this->resourceConnection = null;
        }
        $this->logger->debug("All connections closed.");
    }

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

    private function listen(): void
    {
        while (true) {
            $read  = array_map(fn(WSResource $res) => $res->getConnect(), $this->connects);
            $read[] = $this->resourceConnection;
            $write = [];
            foreach ($this->connects as $resource) {
                if (strlen($resource->writeBuffer) > 0) {
                    $write[] = $resource->getConnect();
                }
            }
            $except = null;

            $seconds      = intdiv($this->loopInterval, 1_000_000);
            $microseconds = $this->loopInterval % 1_000_000;
            $activity     = @stream_select($read, $write, $except, $seconds, $microseconds);
            if ($activity === false) {
                continue;
            }

            if (in_array($this->resourceConnection, $read, true)) {
                if ($newConnection = stream_socket_accept($this->resourceConnection, 0)) {
                    stream_set_blocking($newConnection, false);
                    $info = WebSocketProtocol::handshake($newConnection);
                    if ($info !== false) {
                        $resource = new WSResource($newConnection, $info);
                        $this->connects[(string) $newConnection] = $resource;
                        $this->logger->debug("New client connected: {$resource}");
                        try {
                            $this->handleConnect($resource);
                        } catch (\Throwable $exception) {
                            $this->logger->error('handlerConnect: ' . $exception->getMessage());
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
                            $this->logger->warning(
                                "Received '{$msg->type}' frame from {$resource}. Closing connection."
                            );
                        }
                        $this->disconnectClient($resource);
                        break;
                    }

                    try {
                        $this->handle($resource, $msg);
                    } catch (\Throwable $exception) {
                        $this->logger->error('handler: ' . $exception->getMessage());
                    }
                }
            }

            foreach ($write as $connect) {
                $resource     = $this->connects[(string) $connect];
                $bytesWritten = @fwrite($connect, $resource->writeBuffer);
                if ($bytesWritten === false) {
                    $this->disconnectClient($resource);
                    continue;
                }
                $resource->writeBuffer = $bytesWritten === strlen($resource->writeBuffer)
                    ? ''
                    : substr($resource->writeBuffer, $bytesWritten);
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
}
