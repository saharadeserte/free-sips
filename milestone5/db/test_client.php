<?php
declare(strict_types=1);

date_default_timezone_set('UTC');
require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

Dotenv::createImmutable(__DIR__)->safeLoad();

function env(string $key, string $default = ''): string
{
    $value = $_ENV[$key] ?? getenv($key);
    return (is_string($value) && $value !== '') ? $value : $default;
}

final class Tally
{
    public int $pass = 0;
    public int $fail = 0;

    public function check(string $name, bool $ok, string $detail = ''): void
    {
        $ok ? $this->pass++ : $this->fail++;
        echo sprintf('[%s] %s', $ok ? 'PASS' : 'FAIL', $name);
        if (!$ok && $detail !== '') {
            echo "  -> {$detail}";
        }
        echo PHP_EOL;
    }
}

/** Sends a request and waits for the reply with the same correlation_id. */
final class Rpc
{
    private array $replies = [];
    private string $replyQueue;

    public function __construct(private AMQPChannel $channel, private string $queue)
    {
        // Temporary, exclusive reply queue. The broker deletes it when this script exits.
        [$this->replyQueue] = $channel->queue_declare('', false, false, true, true);

        $channel->basic_consume(
            $this->replyQueue, '', false, true, false, false,
            function (AMQPMessage $msg): void {
                $this->replies[(string) $msg->get('correlation_id')] =
                    json_decode($msg->getBody(), true) ?? [];
            }
        );
    }

    public function call(array $payload, float $timeout = 10.0): array
    {
        $corr = bin2hex(random_bytes(8));

        $this->channel->basic_publish(
            new AMQPMessage(json_encode($payload, JSON_THROW_ON_ERROR), [
                'content_type'   => 'application/json',
                'correlation_id' => $corr,
                'reply_to'       => $this->replyQueue,
            ]),
            '',
            $this->queue
        );

        $deadline = microtime(true) + $timeout;

        while (!isset($this->replies[$corr])) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException(
                    "No reply to '{$payload['action']}' within {$timeout}s. Is the worker running?"
                );
            }

            try {
                $this->channel->wait(null, false, 0.5);
            } catch (AMQPTimeoutException) {
                // Nothing arrived in this slice. Keep waiting until the deadline.
            }
        }

        return $this->replies[$corr];
    }
}

// ---------------------------------------------------------------

$connection = new AMQPStreamConnection(
    env('RMQ_HOST'),
    (int) env('RMQ_PORT', '5672'),
    env('RMQ_USER'),
    env('RMQ_PASS'),
    env('RMQ_VHOST', 'messenger')
);
$channel = $connection->channel();

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        env('DB_HOST', '127.0.0.1'),
        env('DB_PORT', '3306'),
        env('DB_NAME', 'chefs_Db')
    ),
    env('DB_USER'),
    env('DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$t = new Tally();
$auth = new Rpc($channel, env('RMQ_QUEUE', 'databaseQueue'));

$password  = 'Test-pass-123!';
$suffix    = date('mdHis') . random_int(100, 999);
$username  = "test_{$suffix}";
$email     = "test_{$suffix}@example.test";
$userId    = 0;
$eventUuid = '';

try {
    echo "== Users ==\n";

    $r = $auth->call([
        'action'        => 'register',
        'username'      => $username,
        'email'         => $email,
        'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
    ]);
    $t->check('register creates a user', ($r['success'] ?? false) === true, json_encode($r));
    $userId = (int) ($r['user_id'] ?? 0);

    if ($userId === 0) {
        throw new RuntimeException('Cannot continue without a test user.');
    }

    $r = $auth->call([
        'action'        => 'register',
        'username'      => $username,
        'email'         => $email,
        'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
    ]);
    $t->check('duplicate register is rejected', ($r['error'] ?? '') === 'username_or_email_taken', json_encode($r));

    $r = $auth->call(['action' => 'login', 'identifier' => $username]);
    $hash = $r['user']['password_hash'] ?? '';
    $t->check('login finds the user', ($r['success'] ?? false) === true && $hash !== '', json_encode($r));
    $t->check('stored hash verifies the password', password_verify($password, $hash));
    $t->check('stored hash is argon2id', (password_get_info($hash)['algoName'] ?? '') === 'argon2id');

    $r = $auth->call([
        'action'        => 'update_password_hash',
        'user_id'       => $userId,
        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
    ]);
    $t->check('bcrypt hash is refused by update_password_hash', ($r['error'] ?? '') === 'hash_must_be_argon2id', json_encode($r));

    $r = $auth->call(['action' => 'login', 'identifier' => 'no_such_user_' . $suffix]);
    $t->check('unknown user returns user_not_found', ($r['error'] ?? '') === 'user_not_found', json_encode($r));

    echo "\n== Login lockout ==\n";

    for ($i = 0; $i < 5; $i++) {
        $auth->call(['action' => 'login_result', 'user_id' => $userId, 'success' => false]);
    }

    $r = $auth->call(['action' => 'login', 'identifier' => $username]);
    $t->check('five failures set failed_logins to 5', (int) ($r['user']['failed_logins'] ?? -1) === 5);
    $t->check('five failures lock the account', ($r['user']['locked_until'] ?? null) !== null);

    $auth->call(['action' => 'login_result', 'user_id' => $userId, 'success' => true]);

    $r = $auth->call(['action' => 'login', 'identifier' => $username]);
    $t->check('successful login resets the counter', (int) ($r['user']['failed_logins'] ?? -1) === 0);
    $t->check('successful login clears the lock', array_key_exists('locked_until', $r['user'] ?? []) && $r['user']['locked_until'] === null);

    echo "\n== Sessions ==\n";

    $s = $auth->call(['action' => 'create_session', 'user_id' => $userId]);
    $t->check(
        'create_session returns a 64-character hex token',
        ($s['success'] ?? false) === true && preg_match('/^[0-9a-f]{64}$/', $s['session_token'] ?? '') === 1,
        json_encode($s)
    );
    $token     = (string) ($s['session_token'] ?? '');
    $sessionId = (int) ($s['session_id'] ?? 0);

    $stmt = $pdo->prepare('SELECT token_hash FROM auth_sessions WHERE session_id = ?');
    $stmt->execute([$sessionId]);
    $stored = $stmt->fetchColumn();
    $t->check('database stores the SHA-256 hash, not the token', $stored === hash('sha256', $token) && $stored !== $token);

    $v = $auth->call(['action' => 'validate_session', 'session_token' => $token]);
    $t->check(
        'validate_session accepts a fresh token',
        ($v['success'] ?? false) === true && ($v['session']['username'] ?? '') === $username,
        json_encode($v)
    );

    $v = $auth->call(['action' => 'validate_session', 'session_token' => str_repeat('0', 64)]);
    $t->check('unknown token is rejected', ($v['error'] ?? '') === 'session_invalid', json_encode($v));

    $v = $auth->call(['action' => 'validate_session', 'session_token' => 'not-a-token']);
    $t->check('malformed token is rejected', ($v['error'] ?? '') === 'invalid_session_token', json_encode($v));

    $r = $auth->call(['action' => 'revoke_session', 'session_token' => $token]);
    $t->check('revoke_session reports revoked', ($r['revoked'] ?? false) === true, json_encode($r));

    $v = $auth->call(['action' => 'validate_session', 'session_token' => $token]);
    $t->check('revoked session is rejected', ($v['error'] ?? '') === 'session_invalid', json_encode($v));

    $r = $auth->call(['action' => 'revoke_session', 'session_token' => $token]);
    $t->check('revoking twice is not an error', ($r['success'] ?? false) === true && ($r['revoked'] ?? true) === false);

    $a = $auth->call(['action' => 'create_session', 'user_id' => $userId]);
    $b = $auth->call(['action' => 'create_session', 'user_id' => $userId]);

    $r = $auth->call(['action' => 'revoke_user_sessions', 'user_id' => $userId]);
    $t->check('revoke_user_sessions revokes both active sessions', (int) ($r['revoked_count'] ?? -1) === 2, json_encode($r));

    $va = $auth->call(['action' => 'validate_session', 'session_token' => $a['session_token'] ?? '']);
    $vb = $auth->call(['action' => 'validate_session', 'session_token' => $b['session_token'] ?? '']);
    $t->check('both sessions are rejected after bulk revoke', ($va['error'] ?? '') === 'session_invalid' && ($vb['error'] ?? '') === 'session_invalid');

    $c = $auth->call(['action' => 'create_session', 'user_id' => $userId]);
    $pdo->prepare('UPDATE users SET is_active = 0 WHERE user_id = ?')->execute([$userId]);

    $v = $auth->call(['action' => 'validate_session', 'session_token' => $c['session_token'] ?? '']);
    $t->check('deactivated user loses access immediately', ($v['error'] ?? '') === 'session_invalid', json_encode($v));

    $r = $auth->call(['action' => 'create_session', 'user_id' => $userId]);
    $t->check('inactive user cannot get a new session', ($r['error'] ?? '') === 'user_not_found_or_inactive', json_encode($r));

    $pdo->prepare('UPDATE users SET is_active = 1 WHERE user_id = ?')->execute([$userId]);

    $r = $auth->call(['action' => 'create_session', 'user_id' => 999999999]);
    $t->check('unknown user cannot get a session', ($r['error'] ?? '') === 'user_not_found_or_inactive', json_encode($r));

    $r = $auth->call(['action' => 'create_session', 'user_id' => $userId, 'ttl_seconds' => 999999999]);
    $t->check('TTL above the maximum is rejected', ($r['error'] ?? '') === 'invalid_ttl', json_encode($r));

    $e = $auth->call(['action' => 'create_session', 'user_id' => $userId]);
    $pdo->prepare('UPDATE auth_sessions SET expires_at = ? WHERE session_id = ?')
        ->execute(['2000-01-01 00:00:00', (int) ($e['session_id'] ?? 0)]);
    $v = $auth->call(['action' => 'validate_session', 'session_token' => $e['session_token'] ?? '']);
    $t->check('expired session is rejected', ($v['error'] ?? '') === 'session_invalid', json_encode($v));

    echo "\n== Log consumer ==\n";

    $logQueue = env('RMQ_LOG_QUEUE', 'q_central_logging');
    $eventUuid = sprintf(
        '%08x-%04x-4%03x-%04x-%012x',
        random_int(0, 0xffffffff),
        random_int(0, 0xffff),
        random_int(0, 0xfff),
        random_int(0x8000, 0xbfff),
        random_int(0, 0xffffffffffff)
    );

    $logBody = json_encode([
        'source'         => 'test-client',
        'level'          => 'warning',
        'message'        => "test event {$eventUuid}",
        'host'           => 'test-host',
        'context'        => ['test' => true],
        'correlation_id' => $eventUuid,
        'timestamp'      => gmdate('c'),
    ], JSON_THROW_ON_ERROR);

    $props = ['content_type' => 'application/json', 'message_id' => $eventUuid];

    // Publish the same event twice. Only one row should be stored.
    $channel->basic_publish(new AMQPMessage($logBody, $props), '', $logQueue);
    $channel->basic_publish(new AMQPMessage($logBody, $props), '', $logQueue);

    sleep(3);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM sys_logs WHERE event_uuid = ?');
    $stmt->execute([$eventUuid]);
    $rows = (int) $stmt->fetchColumn();
    $t->check('two publishes produce one stored row', $rows === 1, "found {$rows} rows");

    $stmt = $pdo->prepare(
        'SELECT l.level_name, s.source_name
         FROM sys_logs AS sl
         JOIN log_levels AS l ON l.level_id = sl.level_id
         JOIN log_sources AS s ON s.source_id = sl.source_id
         WHERE sl.event_uuid = ?'
    );
    $stmt->execute([$eventUuid]);
    $row = $stmt->fetch();
    $t->check(
        'level and source are mapped correctly',
        ($row['level_name'] ?? '') === 'warning' && ($row['source_name'] ?? '') === 'test-client',
        json_encode($row)
    );

    $queue = $channel->queue_declare($logQueue, true);
    $t->check('log queue is empty after consumption', (int) $queue[1] === 0, "{$queue[1]} messages still waiting");

} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
    $t->fail++;
} finally {
    echo "\n== Cleanup ==\n";

    if ($eventUuid !== '') {
        $pdo->prepare('DELETE FROM sys_logs WHERE event_uuid = ?')->execute([$eventUuid]);
    }

    if ($userId > 0) {
        $pdo->prepare('DELETE FROM auth_sessions WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM users WHERE user_id = ?')->execute([$userId]);
        echo "Removed test user {$username}\n";
    }

    $channel->close();
    $connection->close();
}

echo "\nResult: {$t->pass} passed, {$t->fail} failed\n";
exit($t->fail === 0 ? 0 : 1);
