<?php
require __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$pdo = new PDO(
    'mysql:host=localhost;dbname=chefs_Db;charset=utf8mb4',
    'chef_admin', 'ChefsDbPass123',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$stmt = $pdo->prepare("INSERT INTO sys_logs (Log_LVL, message, source) VALUES (?, ?, ?)");

$conn = new AMQPStreamConnection('100.100.36.22', 5672, 'bigboss', 'secure', 'messenger');
$ch = $conn->channel();
$ch->queue_declare('q_central_logging', false, true, false, false);
$ch->basic_qos(null, 1, null);

$ch->basic_consume('q_central_logging', '', false, false, false, false,
    function (AMQPMessage $msg) use ($stmt) {
        $body = $msg->getBody();
        $data = json_decode($body, true) ?: [];
        $stmt->execute([
            strtoupper($data['level'] ?? 'INFO'),
            $data['message'] ?? $body,
            $data['service'] ?? 'frontend',
        ]);
        $msg->ack();
        echo "[x] stored: " . ($data['level'] ?? '?') . " " . ($data['message'] ?? $body) . "\n";
    }
);

echo "[*] Waiting for messages. Ctrl+C to stop.\n";
while ($ch->is_consuming()) {
    $ch->wait();
}
