<?php
// Usage: <something that outputs log lines> | php ingest_logs.php <source-label>

$source = $argv[1] ?? 'unknown';

$pdo = new PDO(
    'mysql:host=localhost;dbname=chefs_Db;charset=utf8mb4',
    'chef_admin',
    'ChefsDbPass123',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$stmt = $pdo->prepare(
    "INSERT INTO sys_logs (Log_LVL, message, source) VALUES (?, ?, ?)"
);

function detectLevel(string $line): string {
    if (preg_match('/\b(DEBUG|INFO|WARN(?:ING)?|ERROR|CRIT(?:ICAL)?|FATAL)\b/i', $line, $m)) {
        $lvl = strtoupper($m[1]);
        return match (true) {
            str_starts_with($lvl, 'WARN') => 'WARNING',
            str_starts_with($lvl, 'CRIT'),
            $lvl === 'FATAL'              => 'CRITICAL',
            default                       => $lvl,
        };
    }
    if (str_contains($line, '[!]')) return 'WARNING';
    return 'INFO';
}

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') continue;

    try {
        $stmt->execute([detectLevel($line), $line, $source]);
    } catch (PDOException $e) {
        fwrite(STDERR, "DB insert failed: " . $e->getMessage() . "\n");
    }
}
?>
