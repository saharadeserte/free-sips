<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/users_auth.php';

use Dotenv\Dotenv;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

// Load .env if present. Real environment variables take precedence.
Dotenv::createImmutable(__DIR__)->safeLoad();

function env(string $key, ?string $default = null): string
{
    $value = $_ENV[$key] ?? getenv($key);

    if (is_string($value) && $value !== '') {
        return $value;
    }

    if ($default !== null) {
        return $default;
    }

    fwrite(STDERR, "Missing required environment variable: {$key}\n");
    exit(1);
}

$rmqConfig = [
    'host'         => env('RMQ_HOST'),
    'port'         => (int) env('RMQ_PORT', '5672'),
    'user'         => env('RMQ_USER'),
    'pass'         => env('RMQ_PASS'),
    'vhost'        => env('RMQ_VHOST', 'messenger'),
    'queue'        => env('RMQ_QUEUE', 'databaseQueue'),
    'prefetch'     => (int) env('RMQ_PREFETCH', '1'),
    'heartbeat'    => (int) env('RMQ_HEARTBEAT', '60'),
    'consumer_tag' => env('RMQ_CONSUMER_TAG', 'auth-db-worker'),
];

$dbConfig = [
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => (int) env('DB_PORT', '3306'),
    'name' => env('DB_NAME', 'chefs_Db'),
    'user' => env('DB_USER'),
    'pass' => env('DB_PASS'),
];

final class AuthDbWorker
{
    private const MAX_BACKOFF_SECONDS = 30;

    private ?AMQPStreamConnection $connection = null;
    private ?AMQPChannel $channel = null;
    private PDO $pdo;

    public function __construct(private array $rmq, private array $db)
    {
    }

    public function run(): void
    {
        $backoff = 1;

        while (true) {
            try {
                $this->pdo = $this->makePdo();
                $this->connectRabbit();
                echo date('c') . " Listening on '{$this->rmq['queue']}' (vhost {$this->rmq['vhost']})\n";

                $backoff = 1;
                $this->consume();
            } catch (Throwable $e) {
                error_log('Worker connection error: ' . $e->getMessage());
            }

            $this->closeRabbit();
            sleep($backoff);
            $backoff = min($backoff * 2, self::MAX_BACKOFF_SECONDS);
        }
    }

    private function connectRabbit(): void
    {
        $this->connection = new AMQPStreamConnection(
            host: $this->rmq['host'],
            port: $this->rmq['port'],
            user: $this->rmq['user'],
            password: $this->rmq['pass'],
            vhost: $this->rmq['vhost'],
            connection_timeout: 10.0,
            read_write_timeout: $this->rmq['heartbeat'] * 2 + 10,
            heartbeat: $this->rmq['heartbeat']
        );

        $this->channel = $this->connection->channel();

        // Must match the existing queue: durable, not exclusive, not auto-delete.
        $this->channel->queue_declare($this->rmq['queue'], false, true, false, false);

        $this->channel->basic_qos(0, $this->rmq['prefetch'], false);
        $this->channel->confirm_select();

        $this->channel->basic_consume(
            $this->rmq['queue'],
            $this->rmq['consumer_tag'],
            false, // no_local
            false, // no_ack: we ack manually
            false, // exclusive
            false, // nowait
            fn (AMQPMessage $message) => $this->handleMessage($message)
        );
    }

    private function consume(): void
    {
        while ($this->channel->is_consuming()) {
            try {
                $this->channel->wait(null, false, 1.0);
            } catch (AMQPTimeoutException) {
                // No message arrived in time. Send a heartbeat so the broker keeps the connection.
                $this->connection->checkHeartbeat();
            }
        }
    }

    private function handleMessage(AMQPMessage $message): void
    {
        $props         = $message->get_properties();
        $replyTo       = (string) ($props['reply_to'] ?? '');
        $correlationId = (string) ($props['correlation_id'] ?? '');
        $redelivered   = (bool) ($message->delivery_info['redelivered'] ?? false);

        // Without these fields no reply is possible, so reject without requeue.
        if ($replyTo === '' || $correlationId === '') {
            error_log('Rejected message with missing reply_to or correlation_id');
            $message->nack(false, false);
            return;
        }

        $request = json_decode($message->getBody(), true);

        if (!is_array($request)) {
            $this->respond($message, $replyTo, $correlationId, 'unknown', [
                'success' => false,
                'error'   => 'invalid_json',
            ]);
            return;
        }

        $action = (string) ($request['action'] ?? 'unknown');

        // If the database is unreachable, let the exception escape. run() will
        // reconnect, and RabbitMQ will requeue this unacknowledged message.
        $this->ensureDatabase();

        try {
            $response = processRequest($this->pdo, $request);
        } catch (PDOException $e) {
            if ($this->isTransient($e) && !$redelivered) {
                error_log("Transient DB error on '{$action}', requeueing once: " . $e->getMessage());
                $message->nack(false, true);
                return;
            }

            error_log("Database error on '{$action}': " . $e->getMessage());
            $response = ['success' => false, 'error' => 'database_error'];
        } catch (Throwable $e) {
            error_log("Request '{$action}' failed: " . $e->getMessage());
            $response = ['success' => false, 'error' => 'request_processing_failed'];
        }

        $this->respond($message, $replyTo, $correlationId, $action, $response);
    }

    private function respond(
        AMQPMessage $message,
        string $replyTo,
        string $correlationId,
        string $action,
        array $response
    ): void {
        try {
            $reply = new AMQPMessage(
                json_encode($response, JSON_THROW_ON_ERROR),
                [
                    'content_type'   => 'application/json',
                    'correlation_id' => $correlationId,
                    'type'           => $action,
                    'app_id'         => 'auth-db-worker',
                    'timestamp'      => time(),
                ]
            );

            // Default exchange; the routing key is the caller's reply queue.
            $channel = $message->getChannel();
            $channel->basic_publish($reply, '', $replyTo);
            $channel->wait_for_pending_acks(10.0);

            // Acknowledge the request only after the broker confirms the reply.
            $message->ack();
        } catch (Throwable $e) {
            // The database work may already be committed. Log the correlation_id
            // so the outcome can be reconciled.
            error_log("Reply failed correlation_id={$correlationId} action={$action}: " . $e->getMessage());
            $message->nack(false, false);
        }
    }

    private function ensureDatabase(): void
    {
        try {
            $this->pdo->query('SELECT 1');
        } catch (PDOException) {
            // Connection went stale (for example, after wait_timeout). Reconnect.
            // If the database is down, this throws and run() handles the backoff.
            $this->pdo = $this->makePdo();
        }
    }

    private function makePdo(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->db['host'],
            $this->db['port'],
            $this->db['name']
        );

        return new PDO($dsn, $this->db['user'], $this->db['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
    }

    private function isTransient(PDOException $e): bool
    {
        // 2006 server gone away, 2013 lost connection,
        // 1205 lock wait timeout, 1213 deadlock.
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return in_array($driverCode, [2006, 2013, 1205, 1213], true);
    }

    private function closeRabbit(): void
    {
        try {
            $this->channel?->close();
        } catch (Throwable) {
            // Already closed.
        }

        try {
            $this->connection?->close();
        } catch (Throwable) {
            // Already closed.
        }

        $this->channel = null;
        $this->connection = null;
    }
}

(new AuthDbWorker($rmqConfig, $dbConfig))->run();
