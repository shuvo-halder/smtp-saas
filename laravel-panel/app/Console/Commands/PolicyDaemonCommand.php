<?php

namespace App\Console\Commands;

use App\Services\Policy\PolicyDecisionService;
use App\Services\Policy\PostfixPolicyParser;
use App\Services\Policy\PolicyResponse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use OverflowException;
use Throwable;

class PolicyDaemonCommand extends Command
{
    protected $signature = 'policy:serve
                            {--host=127.0.0.1 : IP address to bind (TCP)}
                            {--port=10031 : TCP port to listen on}
                            {--socket= : UNIX socket path (overrides host and port)}
                            {--timeout=30 : Client read timeout in seconds}
                            {--max-requests=0 : Maximum requests before graceful worker cycle (0 for unlimited)}';

    protected $description = 'Runs the Postfix SMTP Outbound Quota Policy Daemon';

    private bool $running = true;

    public function handle(PolicyDecisionService $decisionService): int
    {
        $socketPath = $this->option('socket');
        $host = (string)$this->option('host');
        $port = (int)$this->option('port');
        $timeout = (int)$this->option('timeout');
        $maxRequests = (int)$this->option('max-requests');

        $endpoint = $socketPath ? "unix://{$socketPath}" : "tcp://{$host}:{$port}";

        if ($socketPath && file_exists($socketPath)) {
            @unlink($socketPath);
        }

        $context = stream_context_create();
        $errno = 0;
        $errstr = '';

        $server = @stream_socket_server(
            $endpoint,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );

        if (!$server) {
            $msg = "Failed to bind policy server on {$endpoint}: [{$errno}] {$errstr}";
            $this->error($msg);
            $this->log('error', $msg);
            return 1;
        }

        // Set non-blocking on server socket so stream_select works cleanly
        stream_set_blocking($server, false);

        if ($socketPath) {
            // Secure UNIX domain socket permissions (rw-rw----)
            @chmod($socketPath, 0660);
        }

        $startMsg = "SMTP Policy Daemon listening on {$endpoint}";
        $this->info($startMsg);
        $this->log('info', $startMsg, [
            'endpoint' => $endpoint,
            'timeout'  => $timeout,
        ]);

        // Register signal handlers if pcntl is available (Linux production)
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function () {
                $this->running = false;
            });
            pcntl_signal(SIGINT, function () {
                $this->running = false;
            });
        }

        $totalRequests = 0;

        while ($this->running) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $read = [$server];
            $write = null;
            $except = null;

            // Wait up to 1 second for incoming connection
            $numChanged = @stream_select($read, $write, $except, 1, 0);
            if ($numChanged === false || $numChanged === 0) {
                continue;
            }

            $client = @stream_socket_accept($server, 1);
            if (!$client) {
                continue;
            }

            stream_set_timeout($client, $timeout);
            stream_set_blocking($client, true);

            $this->handleClient($client, $decisionService, $totalRequests);

            if ($maxRequests > 0 && $totalRequests >= $maxRequests) {
                $this->info("Reached maximum request count ({$maxRequests}), cycling worker...");
                break;
            }
        }

        @fclose($server);
        if ($socketPath && file_exists($socketPath)) {
            @unlink($socketPath);
        }

        $stopMsg = "SMTP Policy Daemon stopped cleanly";
        $this->info($stopMsg);
        $this->log('info', $stopMsg);

        return 0;
    }

    /**
     * Handles an individual client connection, supporting multiple requests on persistent connections.
     */
    private function handleClient($client, PolicyDecisionService $decisionService, int &$totalRequests): void
    {
        $parser = new PostfixPolicyParser();

        try {
            while (!feof($client)) {
                $chunk = @fread($client, 4096);
                if ($chunk === false || $chunk === '') {
                    break;
                }

                try {
                    $requests = $parser->feed($chunk);
                } catch (OverflowException $e) {
                    $this->log('warning', 'Malformed/oversized policy request received', [
                        'exception' => $e->getMessage(),
                    ]);
                    $errResp = PolicyResponse::invalid('Request too large')->toPostfixResponse();
                    @fwrite($client, $errResp);
                    break;
                }

                foreach ($requests as $request) {
                    $totalRequests++;
                    $response = $decisionService->evaluate($request);
                    $rawResponse = $response->toPostfixResponse();

                    $written = @fwrite($client, $rawResponse);
                    @fflush($client);
                    if ($written === false) {
                        break 2;
                    }
                }
            }
        } catch (Throwable $e) {
            $this->log('error', 'Unexpected error handling policy client connection', [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);
            // Fail-open response before closing
            @fwrite($client, PolicyResponse::failOpen('Connection error')->toPostfixResponse());
        } finally {
            @fclose($client);
        }
    }

    private function log(string $level, string $message, array $context = []): void
    {
        try {
            Log::channel('policy')->log($level, "[DAEMON] {$message}", $context);
        } catch (Throwable) {
            Log::log($level, "[DAEMON] {$message}", $context);
        }
    }
}
