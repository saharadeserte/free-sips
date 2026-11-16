<?php
declare(strict_types=1);

// central logging consumer (q_central_logging -> sys_logs)
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Wire\AMQPTable;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

Dotenv::createImmutable(__DIR__)->safeLoad();

function envLog(string $key, ?string $default = null): string
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

function generateUuidV4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

$rmqConfig = [
    'hosts'        => envLog('RMQ_HOSTS', envLog('RMQ_HOST', '100.100.36.22,100.117.84.32,100.99.135.24')),
    'port'         => (int) envLog('RMQ_PORT', '5672'),
    'user'         => envLog('RMQ_USER', 'bigboss'),
    'pass'         => envLog('RMQ_PASS', envLog('RMQ_PASSWORD', 'secure')),
    'vhost'        => envLog('RMQ_VHOST', 'messenger'),
    'queue'        => envLog('RMQ_LOG_QUEUE', 'q_central_logging'),
    'prefetch'     => 10,
    'heartbeat'    => (int) envLog('RMQ_HEARTBEAT', '60'),
    'consumer_tag' => 'central-log-worker-' . gethostname(),
];

$dbConfig = [
    'host' => envLog('DB_HOST', '127.0.0.1'),
    'port' => (int) envLog('DB_PORT', '3306'),
    'name' => envLog('DB_NAME', 'chefs_Db'),
    'user' => envLog('DB_USER'),
    'pass' => envLog('DB_PASS'),
];

final class CentralLogWorker
{
    private const MAX_BACKOFF_SECONDS = 30;

    // Adjust these IDs if your log_levels / log_sources lookup tables use different keys
    private const LEVEL_MAP = [
        'DEBUG'    => 1,
        'INFO'     => 2,
        'WARNING'  => 3,
        'WARN'     => 3,
        'ERROR'    => 4,
        'CRITICAL' => 5,
    ];

    private const SOURCE_MAP = [
        'frontend'         => 1,
        'backend-frontend' => 2,
        'befe'             => 2,
        'backend-database' => 3,
        'bedb'             => 3,
        'database'         => 4,
        'rabbitmq'         => 5,
    ];

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
                echo date('c') . " Listening for logs on '{$this->rmq['queue']}'\n";

                $backoff = 1;
                $this->consume();
            } catch (Throwable $e) {
                error_log('CentralLogWorker error: ' . $e->getMessage());
            }

            $this->closeRabbit();
            sleep($backoff);
            $backoff = min($backoff * 2, self::MAX_BACKOFF_SECONDS);
        }
    }

    private function connectRabbit(): void
    {
        $hosts = array_filter(array_map('trim', explode(',', $this->rmq['hosts'])));
        shuffle($hosts);

        $this->connection = null;
        $lastException = null;

        foreach ($hosts as $host) {
            try {
                $this->connection = new AMQPStreamConnection(
                    host: $host,
                    port: $this->rmq['port'],
                    user: $this->rmq['user'],
                    password: $this->rmq['pass'],
                    vhost: $this->rmq['vhost'],
                    connection_timeout: 5.0,
                    read_write_timeout: $this->rmq['heartbeat'] * 2 + 10,
                    heartbeat: $this->rmq['heartbeat']
                );
                echo date('c') . " Log worker connected to RabbitMQ node {$host}\n";
                break;
            } catch (Throwable $e) {
                $lastException = $e;
            }
        }

        if (!$this->connection) {
            throw $lastException ?? new RuntimeException('All RabbitMQ hosts unreachable.');
        }

        $this->channel = $this->connection->channel();

        // Must match server.js: durable quorum queue
        $args = new AMQPTable(['x-queue-type' => 'quorum']);
        $this->channel->queue_declare($this->rmq['queue'], false, true, false, false, false, $args);
        $this->channel->basic_qos(0, $this->rmq['prefetch'], false);

        $this->channel->basic_consume(
            $this->rmq['queue'],
            $this->rmq['consumer_tag'],
            false,
            false,
            false,
            false,
            fn (AMQPMessage $msg) => $this->handleLog($msg)
        );
    }

    private function consume(): void
    {
        while ($this->channel->is_consuming()) {
            try {
                $this->channel->wait(null, false, 1.0);
            } catch (AMQPTimeoutException) {
                $this->connection->checkHeartbeat();
            }
        }
    }

    private function handleLog(AMQPMessage $msg): void
    {
        $rawBody = $msg->getBody();
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            $data = ['message' => $rawBody];
        }

        $levelName   = strtoupper(trim((string) ($data['level'] ?? 'INFO')));
        $serviceName = strtolower(trim((string) ($data['service'] ?? 'frontend')));

        $levelId  = self::LEVEL_MAP[$levelName] ?? 2;
        $sourceId = self::SOURCE_MAP[$serviceName] ?? 1;

        $eventUuid     = isset($data['event_uuid']) && strlen((string) $data['event_uuid']) === 36
            ? (string) $data['event_uuid']
            : generateUuidV4();
        $host          = isset($data['host']) ? substr((string) $data['host'], 0, 255) : $serviceName;
        $message       = (string) ($data['message'] ?? $rawBody);
        $correlationId = isset($data['correlation_id']) ? substr((string) $data['correlation_id'], 0, 64) : null;
        $loggedAt      = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');

        try {
            $this->ensureDatabase();
            $stmt = $this->pdo->prepare(
                "INSERT INTO sys_logs (event_uuid, level_id, source_id, host, message, context, correlation_id, logged_at)
                 VALUES (:uuid, :level_id, :source_id, :host, :message, :context, :corr_id, :logged_at)"
            );
            $stmt->execute([
                ':uuid'      => $eventUuid,
                ':level_id'  => $levelId,
                ':source_id' => $sourceId,
                ':host'      => $host,
                ':message'   => $message,
                ':context'   => $rawBody,
                ':corr_id'   => $correlationId,
                ':logged_at' => $loggedAt,
            ]);
            $msg->ack();
        } catch (Throwable $e) {
            error_log('Failed to write log to sys_logs: ' . $e->getMessage());
            $msg->nack(false, true);
        }
    }

    private function ensureDatabase(): void
    {
        try {
            $this->pdo->query('SELECT 1');
        } catch (PDOException) {
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

    private function closeRabbit(): void
    {
        try { $this->channel?->close(); } catch (Throwable) {}
        try { $this->connection?->close(); } catch (Throwable) {}
        $this->channel = null;
        $this->connection = null;
    }
}

(new CentralLogWorker($rmqConfig, $dbConfig))->run();