<?php
declare(strict_types=1);

require_once __DIR__ . '/drinks_api.php';

function processRequest(PDO $pdo, array $request): array
{
    $action = strtolower((string)($request['action'] ?? ''));

    switch ($action) {
        case 'register':
            return registerUser($pdo, $request);

        case 'login':
            return findLoginUser($pdo, $request);

        case 'login_result':
            return updateLoginResult($pdo, $request);

        case 'update_password_hash':
            return updatePasswordHash($pdo, $request);

        case 'update_account':
            return updateAccount($pdo, $request);

        case 'session_create':
            return createSession($pdo, $request);

        case 'session_validate':
            return validateSession($pdo, $request);

        case 'session_delete':
            return deleteSession($pdo, $request);

        default:
            return processDrinkRequest($pdo, $request)
                ?? ['success' => false, 'error' => 'unknown_action'];
    }
}

function updateAccount(PDO $pdo, array $request): array
{
    $userId = filter_var($request['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($userId === false || $userId === null) {
        return ['success' => false, 'error' => 'not_logged_in'];
    }

    $newUsername = trim((string)($request['new_username'] ?? ''));
    $newHash     = (string)($request['new_password_hash'] ?? '');

    if ($newUsername === '' && $newHash === '') {
        return ['success' => false, 'error' => 'nothing_to_update'];
    }

    try {
        if ($newUsername !== '') {
            if (mb_strlen($newUsername) < 2 || mb_strlen($newUsername) > 50) {
                return ['success' => false, 'error' => 'Username must be between 2 and 50 characters.'];
            }
            $stmt = $pdo->prepare('UPDATE users SET username = :u WHERE user_id = :id');
            $stmt->execute(['u' => $newUsername, 'id' => $userId]);
        }

        if ($newHash !== '') {
            $stmt = $pdo->prepare('UPDATE users SET password_hash = :h WHERE user_id = :id');
            $stmt->execute(['h' => $newHash, 'id' => $userId]);
        }

        $q = $pdo->prepare('SELECT username FROM users WHERE user_id = :id LIMIT 1');
        $q->execute(['id' => $userId]);
        $uname = (string)($q->fetchColumn() ?: $newUsername);

        return [
            'success'  => true,
            'username' => $uname,
            'message'  => 'Account settings updated.'
        ];
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            return ['success' => false, 'error' => 'That username is already taken.'];
        }
        throw $e;
    }
}

function registerUser(PDO $pdo, array $request): array
{
    $username = trim((string)($request['username'] ?? ''));
    $email = trim((string)($request['email'] ?? ''));
    $passwordHash = (string)($request['password_hash'] ?? '');

    if ($username === '' || $email === '' || $passwordHash === '') {
        return ['success' => false, 'error' => 'missing_fields'];
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, email, password_hash)
             VALUES (:username, :email, :password_hash)'
        );
        $stmt->execute([
            'username' => $username,
            'email' => $email,
            'password_hash' => $passwordHash
        ]);

        return [
            'success' => true,
            'user_id' => (int)$pdo->lastInsertId(),
            'username' => $username
        ];
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            return ['success' => false, 'error' => 'username_or_email_taken'];
        }
        throw $e;
    }
}

function findLoginUser(PDO $pdo, array $request): array
{
    $identifier = trim((string)(
        $request['identifier']
        ?? $request['username']
        ?? $request['email']
        ?? ''
    ));

    if ($identifier === '') {
        return ['success' => false, 'error' => 'missing_identifier'];
    }

    $stmt = $pdo->prepare(
        'SELECT user_id, username, email, password_hash,
                role, is_active, failed_logins, locked_until
         FROM users
         WHERE username = :username
            OR email = :email
         LIMIT 1'
    );

    $stmt->execute([
        'username' => $identifier,
        'email' => $identifier
    ]);

    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'error' => 'user_not_found'];
    }

    return [
        'success' => true,
        'user' => [
            'user_id' => (int)$user['user_id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'password_hash' => $user['password_hash'],
            'role' => $user['role'],
            'is_active' => (bool)$user['is_active'],
            'failed_logins' => (int)$user['failed_logins'],
            'locked_until' => $user['locked_until']
        ]
    ];
}

function updateLoginResult(PDO $pdo, array $request): array
{
    $success = $request['success'] ?? null;
    $userId = filter_var($request['user_id'] ?? null, FILTER_VALIDATE_INT);
    $username = trim((string)($request['username'] ?? ''));

    if (!is_bool($success)) {
        return ['success' => false, 'error' => 'invalid_success_value'];
    }

    if ($userId !== false && $userId !== null) {
        $where = 'user_id = :identity';
        $identity = $userId;
    } elseif ($username !== '') {
        $where = 'username = :identity';
        $identity = $username;
    } else {
        return ['success' => false, 'error' => 'missing_user_identifier'];
    }

    if ($success) {
        $sql = "UPDATE users
                SET failed_logins = 0,
                    locked_until = NULL,
                    last_login_at = CURRENT_TIMESTAMP
                WHERE {$where}";
    } else {
        $sql = "UPDATE users
                SET failed_logins = failed_logins + 1,
                    locked_until = CASE
                        WHEN failed_logins + 1 >= 5
                        THEN DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE)
                        ELSE locked_until
                    END
                WHERE {$where}";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['identity' => $identity]);

    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare("SELECT user_id FROM users WHERE {$where} LIMIT 1");
        $check->execute(['identity' => $identity]);
        if (!$check->fetch()) {
            return ['success' => false, 'error' => 'user_not_found'];
        }
    }

    return ['success' => true, 'message' => 'login_result_updated'];
}

function updatePasswordHash(PDO $pdo, array $request): array
{
    $newHash = (string)($request['password_hash'] ?? '');
    if ($newHash === '' || strlen($newHash) > 255) {
        return ['success' => false, 'error' => 'invalid_password_hash'];
    }

    $info = password_get_info($newHash);
    if (($info['algoName'] ?? '') !== 'argon2id') {
        return ['success' => false, 'error' => 'hash_must_be_argon2id'];
    }

    $userId = filter_var($request['user_id'] ?? null, FILTER_VALIDATE_INT);
    $username = trim((string)($request['username'] ?? ''));

    if ($userId !== false && $userId !== null) {
        $where = 'user_id = :identity';
        $identity = $userId;
    } elseif ($username !== '') {
        $where = 'username = :identity';
        $identity = $username;
    } else {
        return ['success' => false, 'error' => 'missing_user_identifier'];
    }

    $sql = "UPDATE users SET password_hash = :new_hash WHERE {$where}";
    $params = ['identity' => $identity, 'new_hash' => $newHash];

    if (isset($request['old_password_hash'])) {
        $sql .= ' AND password_hash = :old_hash';
        $params['old_hash'] = (string)$request['old_password_hash'];
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    if ($stmt->rowCount() > 0) {
        return ['success' => true, 'message' => 'password_hash_updated'];
    }

    $check = $pdo->prepare("SELECT password_hash FROM users WHERE {$where} LIMIT 1");
    $check->execute(['identity' => $identity]);
    $row = $check->fetch();

    if (!$row) {
        return ['success' => false, 'error' => 'user_not_found'];
    }
    if ($row['password_hash'] === $newHash) {
        return ['success' => true, 'message' => 'password_hash_already_current'];
    }
    return ['success' => false, 'error' => 'hash_changed'];
}

function createSession(PDO $pdo, array $req): array {
    $userId = (int)($req['user_id'] ?? 0);
    $token  = (string)($req['token'] ?? '');
    if ($userId <= 0 || $token === '') {
        return ['success' => false, 'error' => 'invalid_input'];
    }

    $pdo->exec("DELETE FROM user_sessions WHERE expires_at <= UTC_TIMESTAMP()");

    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare(
        "INSERT INTO user_sessions (token_hash, user_id, expires_at)
         VALUES (:hash, :uid, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR))"
    );
    $stmt->execute([':hash' => $hash, ':uid' => $userId]);

    return ['success' => true];
}

function validateSession(PDO $pdo, array $req): array {
    $token = (string)($req['token'] ?? '');
    if ($token === '') {
        return ['success' => false];
    }

    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare(
        "SELECT u.user_id, u.username
         FROM user_sessions s
         JOIN users u ON u.user_id = s.user_id
         WHERE s.token_hash = :hash
           AND s.expires_at > UTC_TIMESTAMP()
           AND u.is_active = 1
         LIMIT 1"
    );
    $stmt->execute([':hash' => $hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return ['success' => false];
    }

    return [
        'success' => true,
        'user' => [
            'user_id'  => (int)$row['user_id'],
            'username' => $row['username'],
        ]
    ];
}

function deleteSession(PDO $pdo, array $req): array {
    $token = (string)($req['token'] ?? '');
    if ($token !== '') {
        $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE token_hash = :hash");
        $stmt->execute([':hash' => hash('sha256', $token)]);
    }
    return ['success' => true];
}
