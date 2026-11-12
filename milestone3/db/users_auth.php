<?php
declare(strict_types=1);

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

        default:
            return [
                'success' => false,
                'error' => 'unknown_action'
            ];
    }
}

function registerUser(PDO $pdo, array $request): array
{
    $username = trim((string)($request['username'] ?? ''));
    $email = trim((string)($request['email'] ?? ''));
    $passwordHash = (string)($request['password_hash'] ?? '');

    if (
        $username === '' ||
        $email === '' ||
        $passwordHash === ''
    ) {
        return [
            'success' => false,
            'error' => 'missing_fields'
        ];
    }

    // The backend hashes the password before sending it.
    // This function stores the supplied hash.
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
        // MySQL error 1062: duplicate value for a unique key.
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            return [
                'success' => false,
                'error' => 'username_or_email_taken'
            ];
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
        return [
            'success' => false,
            'error' => 'missing_identifier'
        ];
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
        return [
            'success' => false,
            'error' => 'user_not_found'
        ];
    }

    // The calling backend verifies the supplied password
    // against password_hash. Do not send the hash to a browser.
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
    $userId = filter_var(
        $request['user_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    // Username is accepted for compatibility with the
    // current C# test publisher.
    $username = trim((string)($request['username'] ?? ''));

    if (!is_bool($success)) {
        return [
            'success' => false,
            'error' => 'invalid_success_value'
        ];
    }

    if ($userId !== false && $userId !== null) {
        $where = 'user_id = :identity';
        $identity = $userId;
    } elseif ($username !== '') {
        $where = 'username = :identity';
        $identity = $username;
    } else {
        return [
            'success' => false,
            'error' => 'missing_user_identifier'
        ];
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
        $check = $pdo->prepare(
            "SELECT user_id FROM users WHERE {$where} LIMIT 1"
        );
        $check->execute(['identity' => $identity]);

        if (!$check->fetch()) {
            return [
                'success' => false,
                'error' => 'user_not_found'
            ];
        }
    }

    return [
        'success' => true,
        'message' => 'login_result_updated'
    ];
}

/**
 * Replaces a stored password hash, typically when migrating
 * bcrypt users to argon2id after a successful login.
 *
 * Request fields:
 *   user_id or username   identifies the user
 *   password_hash         the new argon2id hash (required)
 *   old_password_hash     optional. If given, the update applies only
 *                         while the stored hash still equals this value,
 *                         so a concurrent password change is not overwritten.
 */
function updatePasswordHash(PDO $pdo, array $request): array
{
    $newHash = (string)($request['password_hash'] ?? '');

    if ($newHash === '' || strlen($newHash) > 255) {
        return [
            'success' => false,
            'error' => 'invalid_password_hash'
        ];
    }

    // Accept only argon2id so the update can't downgrade a user to bcrypt.
    // password_get_info() reports 'unknown' for strings that aren't valid hashes.
    $info = password_get_info($newHash);

    if (($info['algoName'] ?? '') !== 'argon2id') {
        return [
            'success' => false,
            'error' => 'hash_must_be_argon2id'
        ];
    }

    $userId = filter_var(
        $request['user_id'] ?? null,
        FILTER_VALIDATE_INT
    );
    $username = trim((string)($request['username'] ?? ''));

    if ($userId !== false && $userId !== null) {
        $where = 'user_id = :identity';
        $identity = $userId;
    } elseif ($username !== '') {
        $where = 'username = :identity';
        $identity = $username;
    } else {
        return [
            'success' => false,
            'error' => 'missing_user_identifier'
        ];
    }

    $sql = "UPDATE users
            SET password_hash = :new_hash
            WHERE {$where}";
    $params = [
        'identity' => $identity,
        'new_hash' => $newHash
    ];

    if (isset($request['old_password_hash'])) {
        $sql .= ' AND password_hash = :old_hash';
        $params['old_hash'] = (string)$request['old_password_hash'];
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    if ($stmt->rowCount() > 0) {
        return [
            'success' => true,
            'message' => 'password_hash_updated'
        ];
    }

    // No rows changed. Work out why.
    $check = $pdo->prepare(
        "SELECT password_hash FROM users WHERE {$where} LIMIT 1"
    );
    $check->execute(['identity' => $identity]);
    $row = $check->fetch();

    if (!$row) {
        return [
            'success' => false,
            'error' => 'user_not_found'
        ];
    }

    if ($row['password_hash'] === $newHash) {
        return [
            'success' => true,
            'message' => 'password_hash_already_current'
        ];
    }

    // The stored hash differs from old_password_hash, so it changed
    // after the caller verified it. Leave it alone.
    return [
        'success' => false,
        'error' => 'hash_changed'
    ];
}
