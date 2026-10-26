<?php
declare(strict_types=1);

// RabbitMQ log consumer: reads JSON log events from a queue and stores them in sys_logs.
// Needs two config files next to this script (keep both OUT of Git):
//   rmq_config.php  -> return ['host'=>..., 'port'=>5672, 'user'=>..., 'pass'=>..., 'vhost'=>'/', 'queue'=>...];
//   db_config.php   -> return ['dsn'=>'mysql:host=localhost;dbname=chefs_Db;charset=utf8mb4', 'user'=>..., 'pass'=>...];

require __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

date_default_timezone_set('UTC');

$cfg   = require __DIR__ . '/rmq_config.php';
$dbCfg = require __DIR__ . '/db_config.php';

// PLACEHOLDER: these numbers must match the level_id values used in your sys_logs / levels table
const LEVELS = ['debug' => 1, 'info' => 2, 'warning' => 3, 'error' => 4, 'critical' => 5];

// Connection/lock problems worth retrying; anything else is treated as a bad message
const TRANSIENT_DB_CODES = [1040, 1205, 1213, 2002, 2006, 2013];

function db(array $c): PDO {
    $pdo = new PDO($c['dsn'], $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET time_zone = '+00:00'");   // keeps ingested_at comparable to logged_at (both UTC)
    return $pdo;
}

/** Recursively redact anything whose key looks like a secret. */
function scrubContext(array $ctx): array {
    foreach ($ctx as $k => $v) {
        if (is_string($k) && preg_match('/pass(word|wd)|token|secret|authorization|cookie|api[_-]?key/i', $k)) {
            $ctx[$k] = '[redacted]';
        } elseif (is_array($v)) {
            $ctx[$k] = scrubContext($v);
        }
    }
    return $ctx;
}

function resolveSourceId(PDO $pdo, string $name, array &$cache): int {
    if (isset($cache[$name])) return $cache[$name];

    $sel = $pdo->prepare('SELECT source_id FROM log_sources WHERE source_name = ?');
    $sel->execute([$name]);
    $id = $sel->fetchColumn();

    if ($id === false) {
        try {
            $pdo->prepare('INSERT INTO log_sources (source_name) VALUES (?)')->execute([$name]);
            $id = $pdo->lastInsertId();
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) !== 1062) throw $e;
            $sel->execute([$name]);            // another worker created it first
            $id = $sel->fetchColumn();
        }
    }
    return $cache[$name] = (int)$id;
}

function parseLog(string $body): array {
    $d = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($d)) throw new InvalidArgumentException('Payload must be a JSON object');

    $uuid = $d['event_uuid'] ?? null;
    if (!is_string($uuid) || !preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $uuid)) {
        throw new InvalidArgumentException('Missing or invalid event_uuid');
    }

    $level = LEVELS[strtolower((string)($d['level'] ?? ''))] ?? null;
    if ($level === null) throw new InvalidArgumentException('Unknown level');

    $source  = trim((string)($d['source'] ?? ''));
    $message = (string)($d['message'] ?? '');
    if ($source === '' || $message === '') throw new InvalidArgumentException('source and message are required');

    try {
        $loggedAt = (new DateTimeImmutable((string)($d['logged_at'] ?? 'now')))->setTimezone(new DateTimeZone('UTC'));
    } catch (Exception) {
        throw new InvalidArgumentException('Bad logged_at');
    }

    $context = null;
    if (isset($d['context'])) {
        $context = json_encode(
            is_array($d['context']) ? scrubContext($d['context']) : $d['context'],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        );
    }

    return [
        'event_uuid'     => strtolower($uuid),
        'level_id'       => $level,
        'source'         => mb_substr($source, 0, 64),
        'host'           => isset($d['host']) ? mb_substr((string)$d['host'], 0, 255) : null,
        'message'        => mb_strcut($message, 0, 65535, 'UTF-8'),
        'context'        => $context,
        'correlation_id' => isset($d['correlation_id']) ? mb_substr((string)$d['correlation_id'], 0, 64) : null,
        'logged_at'      => $loggedAt->format('Y-m-d H:i:s.v'),
    ];
}

$pdo         = db($dbCfg);
$sourceCache = [];

$connection = new AMQPStreamConnection(
    (string)$cfg['host'], (int)$cfg['port'], (string)$cfg['user'], (string)$cfg['pass'], (string)$cfg['vhost']
);
$ch = $connection->channel();
$ch->queue_declare($cfg['queue'], false, true, false, false);
$ch->basic_qos(null, 10, null);

$handler = function (AMQPMessage $msg) use (&$pdo, &$sourceCache, $dbCfg) {
    try {
        $row = parseLog($msg->getBody());
        $source = $row['source'];
        unset($row['source']);
        $row['source_id'] = resolveSourceId($pdo, $source, $sourceCache);

        $pdo->prepare(
            'INSERT INTO sys_logs
               (event_uuid, level_id, source_id, host, message, context, correlation_id, logged_at)
             VALUES
               (:event_uuid, :level_id, :source_id, :host, :message, :context, :correlation_id, :logged_at)'
        )->execute($row);

        $msg->ack();                           // only after the insert succeeded

    } catch (JsonException | InvalidArgumentException $e) {
        fwrite(STDERR, "Rejected: {$e->getMessage()}\n");
        $msg->reject(false);                   // permanent failure: don't requeue

    } catch (PDOException $e) {
        $code = $e->errorInfo[1] ?? 0;
        if ($code === 1062) {                  // duplicate event_uuid: already stored, redelivery is harmless
            $msg->ack();
            return;
        }
        fwrite(STDERR, "DB error [$code]: {$e->getMessage()}\n");
        if (in_array($code, TRANSIENT_DB_CODES, true)) {
            $msg->reject(true);                // requeue and retry later
            sleep(2);
            $pdo = db($dbCfg);                 // if the DB is still down this throws and the script exits;
        } else {                               // unacked messages go back to the queue automatically
            $msg->reject(false);
        }

    } catch (Throwable $e) {
        fwrite(STDERR, 'Unexpected: ' . $e->getMessage() . "\n");
        $msg->reject(false);                   // don't let one bad message crash-loop the consumer
    }
};

$ch->basic_consume($cfg['queue'], '', false, false, false, false, $handler);
echo "Waiting for messages on {$cfg['queue']} ...\n";

while ($ch->is_consuming()) {
    $ch->wait();
}
?>
