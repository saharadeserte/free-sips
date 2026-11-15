<?php
// worker.php - one process, two queues:
//   bedb_exchange / bedb.rpc  -> q_bedb_rpc      auth RPC (register, login, login_result)
//   q_central_logging         -> sys_logs        log lines from the services
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/users_auth.php';

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

const EXCHANGE_AUTH = 'bedb_exchange';
const ROUTE_AUTH    = 'bedb.rpc';
const QUEUE_AUTH    = 'q_bedb_rpc';
const QUEUE_LOGS    = 'q_central_logging';
const LOG_LEVELS    = ['DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL'];

function env_required(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        fwrite(STDERR, "[-] Missing environment variable: $name\n");
        exit(1);
    }
    return $value;
}

function say(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
}

function connectPdo(): PDO
{
    return new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', env_required('DB_HOST'), env_required('DB_NAME')),
        env_required('DB_USER'),
        env_required('DB_PASS'),
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
}

// Auth RPC: reply to reply_to with the same correlation_id, then ack.
function handleAuth(AMQPChannel $ch, AMQPMessage $msg, PDO $pdo): void
{
    $replyTo = $msg->has('reply_to') ? (string) $msg->get('reply_to') : '';
    $corrId  = $msg->has('correlation_id') ? (string) $msg->get('correlation_id') : '';

    if ($replyTo === '' || $corrId === '') {
        say('[!] Dropping auth request without reply_to or correlation_id');
        $msg->nack(false, false);
        return;
    }

    $request = json_decode($msg->getBody(), true);
    $action  = is_array($request) ? (string) ($request['action'] ?? '?') : '?';

    if (!is_array($request)) {
        $response = ['success' => false, 'error' => 'invalid_json'];
    } else {
        try {
            $response = processRequest($pdo, $request);
        } catch (Throwable $e) {
            // Never log the request body: it can contain password hashes
            say("[!] auth $action failed: " . $e->getMessage());
            $response = ['success' => false, 'error' => 'database_error'];
        }
    }

    $reply = new AMQPMessage(json_encode($response, JSON_THROW_ON_ERROR), [
        'content_type'   => 'application/json',
        'correlation_id' => $corrId,
    ]);
    $ch->basic_publish($reply, '', $replyTo);
    $msg->ack();
    say("[x] auth $action -> success=" . var_export($response['success'] ?? false, true));
}

// Log lines: store them, ack on success, requeue on database failure.
function handleLog(AMQPMessage $msg, PDOStatement $insert): void
{
    $payload = json_decode($msg->getBody(), true);
    if (!is_array($payload) || !isset($payload['message'])) {
        say('[!] Dropping malformed log message');
        $msg->nack(false, false);
        return;
    }

    $level = strtoupper((string) ($payload['level'] ?? 'INFO'));
    if (!in_array($level, LOG_LEVELS, true)) {
        $level = 'INFO';
    }
    $source = (string) ($payload['service'] ?? 'unknown');
    $text   = (string) $payload['message'];
    if (!empty($payload['timestamp'])) {
        $text = '[' . $payload['timestamp'] . '] ' . $text;
    }

    try {
        $insert->execute([$level, $text, $source]);
        $msg->ack();
    } catch (PDOException $e) {
        say('[-] log insert failed, requeueing: ' . $e->getMessage());
        $msg->nack(false, true);
    }
}

function runWorker(): void
{
    $pdo       = connectPdo();
    $logInsert = $pdo->prepare('INSERT INTO sys_logs (Log_LVL, message, source) VALUES (?, ?, ?)');

    $conn = new AMQPStreamConnection(
        host: env_required('RMQ_HOST'),
        port: (int) (getenv('RMQ_PORT') ?: 5672),
        user: env_required('RMQ_USER'),
        password: env_required('RMQ_PASS'),
        vhost: env_required('RMQ_VHOST'),
        connection_timeout: 10.0,
        read_write_timeout: 90.0,
        heartbeat: 60
    );
    $ch = $conn->channel();

    // Declare every queue and exchange this worker uses. The arguments must
    // match what the other services declare (durable, not auto-delete).
    $ch->exchange_declare(EXCHANGE_AUTH, 'direct', false, true, false);
    $ch->queue_declare(QUEUE_AUTH, false, true, false, false);
    $ch->queue_bind(QUEUE_AUTH, EXCHANGE_AUTH, ROUTE_AUTH);
    $ch->queue_declare(QUEUE_LOGS, false, true, false, false);
    $ch->basic_qos(null, 10, null);

    $ch->basic_consume(QUEUE_AUTH, 'auth-worker', false, false, false, false,
        fn (AMQPMessage $m) => handleAuth($ch, $m, $pdo));
    $ch->basic_consume(QUEUE_LOGS, 'log-worker', false, false, false, false,
        fn (AMQPMessage $m) => handleLog($m, $logInsert));

    say('Listening on ' . QUEUE_AUTH . ' and ' . QUEUE_LOGS);

    while ($ch->is_consuming()) {
        try {
            $ch->wait(null, false, 30.0);
        } catch (AMQPTimeoutException) {
            // No messages in 30 seconds; keep waiting
        }
    }
}

// Reconnect forever. A broker outage shouldn't need a manual restart.
while (true) {
    try {
        runWorker();
    } catch (Throwable $e) {
        say('[!] ' . $e->getMessage() . ' (retrying in 5s)');
    }
    sleep(5);
}
