<?php
// drinks_api.php - Milestone 4 handlers. Loaded by users_auth.php, so both
// worker.php and amqp_comsumer.php pick these actions up.
//
// user_id is NEVER read from the browser: befe resolves it from the session token
// and injects it before the request reaches this file.
declare(strict_types=1);

const DRINK_MAX_INGREDIENTS = 15;
const PANTRY_MAX_ITEMS      = 50;
const THUMB_PREFIX          = 'https://www.thecocktaildb.com/';
const ALCOHOL_RE = '/\b(vodka|rum|gin|whiskey|whisky|bourbon|tequila|brandy|cognac|beer|wine|champagne|prosecco|liqueur|schnapps|vermouth|absinthe|mezcal|sake|scotch)\b/u';

/** @return array<string,mixed>|null null = not a drink action */
function processDrinkRequest(PDO $pdo, array $r): ?array
{
    return match (strtolower((string)($r['action'] ?? ''))) {
        'upsert_drink'    => upsertDrink($pdo, $r),
        'list_drinks'     => listDrinks($pdo, $r),
        'list_categories' => listCategories($pdo),
        'get_drink'       => getDrink($pdo, $r),
        'pantry_list'     => pantryList($pdo, $r),
        'pantry_add'      => pantryAdd($pdo, $r),
        'pantry_update'   => pantryUpdate($pdo, $r),
        'pantry_remove'   => pantryRemove($pdo, $r),
        'match_recipes'   => matchRecipes($pdo, $r),
        'submit_recipe'   => submitRecipe($pdo, $r),
        'submit_review'   => submitReview($pdo, $r),
        'delete_review'   => deleteReview($pdo, $r),
        default           => null,
    };
}

// ---------- helpers ----------

function fail(string $error): array { return ['success' => false, 'error' => $error]; }

function cleanText(mixed $raw, int $max): string
{
    $s = is_scalar($raw) ? trim((string)$raw) : '';
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';
    return mb_substr($s, 0, $max, 'UTF-8');
}

function normalizeIngredient(mixed $raw): string
{
    $s = mb_strtolower(is_scalar($raw) ? trim((string)$raw) : '', 'UTF-8');
    $s = preg_replace('/[^\p{L}\p{N} \'\-]/u', '', $s) ?? '';
    $s = preg_replace('/\s+/u', ' ', $s) ?? '';
    return mb_substr(trim($s), 0, 100, 'UTF-8');
}

function userIdOf(array $r): ?int
{
    $id = filter_var($r['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? null : $id;
}

function intIn(mixed $v, int $min, int $max, int $default): int
{
    $n = filter_var($v, FILTER_VALIDATE_INT);
    return $n === false ? $default : max($min, min($max, $n));
}

function thumbOrNull(mixed $raw): ?string
{
    $u = cleanText($raw, 255);
    return str_starts_with($u, THUMB_PREFIX) ? $u : null;
}

/** @return array<string,string>|null name => measure, or null if invalid/too many */
function parseIngredients(mixed $raw): ?array
{
    if (!is_array($raw)) return null;
    $out = [];
    foreach ($raw as $row) {
        if (!is_array($row)) continue;
        $name = normalizeIngredient($row['name'] ?? '');
        if ($name === '') continue;
        $out[$name] = cleanText($row['measure'] ?? '', 60);
        if (count($out) > DRINK_MAX_INGREDIENTS) return null;
    }
    return $out;
}

function ingredientId(PDO $pdo, string $name): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO ingredients (name) VALUES (?)
         ON DUPLICATE KEY UPDATE ingredient_id = LAST_INSERT_ID(ingredient_id)'
    );
    $stmt->execute([$name]);
    return (int)$pdo->lastInsertId();
}

function findIngredientId(PDO $pdo, string $name): ?int
{
    $stmt = $pdo->prepare('SELECT ingredient_id FROM ingredients WHERE name = ?');
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

function saveDrinkIngredients(PDO $pdo, int $drinkId, array $ingredients): void
{
    $pdo->prepare('DELETE FROM drink_ingredients WHERE drink_id = ?')->execute([$drinkId]);
    $ins = $pdo->prepare(
        'INSERT INTO drink_ingredients (drink_id, ingredient_id, measure, position) VALUES (?, ?, ?, ?)'
    );
    $pos = 0;
    foreach ($ingredients as $name => $measure) {
        $ins->execute([$drinkId, ingredientId($pdo, (string)$name), $measure === '' ? null : $measure, ++$pos]);
    }
}

function inTransaction(PDO $pdo, callable $fn): mixed
{
    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e; // the consumer decides whether a deadlock/lost connection is retried
    }
}

// ---------- ingestion (called by BE-DB's DrinkIngestionWorker) ----------

function upsertDrink(PDO $pdo, array $r): array
{
    $externalId = cleanText($r['external_id'] ?? '', 20);
    $name       = cleanText($r['name'] ?? '', 150);
    if ($externalId === '' || $name === '') return fail('missing_fields');
    if (($r['non_alcoholic'] ?? false) !== true) return fail('not_non_alcoholic');

    $ingredients = parseIngredients($r['ingredients'] ?? null);
    if (!$ingredients) return fail('invalid_ingredients');

    $drinkId = inTransaction($pdo, function () use ($pdo, $r, $externalId, $name, $ingredients): int {
        $stmt = $pdo->prepare(
            "INSERT INTO drinks (external_id, name, category, glass, instructions, thumb_url, source, status)
             VALUES (?, ?, ?, ?, ?, ?, 'cocktaildb', 'published')
             ON DUPLICATE KEY UPDATE
                name = VALUES(name), category = VALUES(category), glass = VALUES(glass),
                instructions = VALUES(instructions), thumb_url = VALUES(thumb_url),
                drink_id = LAST_INSERT_ID(drink_id)"
        );
        $stmt->execute([
            $externalId,
            $name,
            cleanText($r['category'] ?? '', 60) ?: null,
            cleanText($r['glass'] ?? '', 60) ?: null,
            cleanText($r['instructions'] ?? '', 5000) ?: null,
            thumbOrNull($r['thumb_url'] ?? ''),
        ]);
        $id = (int)$pdo->lastInsertId();
        saveDrinkIngredients($pdo, $id, $ingredients);
        return $id;
    });

    return ['success' => true, 'drink_id' => $drinkId, 'external_id' => $externalId];
}

// ---------- menu ----------

function listDrinks(PDO $pdo, array $r): array
{
    $uid      = userIdOf($r);
    $q        = cleanText($r['q'] ?? '', 60);
    $category = cleanText($r['category'] ?? '', 60);
    $limit    = intIn($r['limit'] ?? 24, 1, 100, 24);   // ints are interpolated: LIMIT can't take bound strings
    $offset   = intIn($r['offset'] ?? 0, 0, 100000, 0);

    $where  = ["(d.status = 'published' OR d.created_by = ?)"];
    $params = [$uid];
    if ($q !== '')        { $where[] = 'd.name LIKE ?';  $params[] = '%' . addcslashes($q, '%_\\') . '%'; }
    if ($category !== '') { $where[] = 'd.category = ?'; $params[] = $category; }

    $stmt = $pdo->prepare(
        "SELECT d.drink_id, d.name, d.category, d.thumb_url, d.source, d.status,
                (SELECT ROUND(AVG(rt.rating), 1) FROM reviews rt WHERE rt.drink_id = d.drink_id) AS avg_rating,
                (SELECT COUNT(*) FROM reviews rt WHERE rt.drink_id = d.drink_id)               AS review_count
         FROM drinks d
         WHERE " . implode(' AND ', $where) . "
         ORDER BY d.name
         LIMIT $limit OFFSET $offset"
    );
    $stmt->execute($params);

    $drinks = array_map(static fn (array $d): array => [
        'drink_id'     => (int)$d['drink_id'],
        'name'         => $d['name'],
        'category'     => $d['category'],
        'thumb_url'    => $d['thumb_url'],
        'source'       => $d['source'],
        'status'       => $d['status'],
        'avg_rating'   => $d['avg_rating'] === null ? null : (float)$d['avg_rating'],
        'review_count' => (int)$d['review_count'],
    ], $stmt->fetchAll());

    return ['success' => true, 'drinks' => $drinks];
}

function listCategories(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT category AS name, COUNT(*) AS count FROM drinks
         WHERE status = 'published' AND category IS NOT NULL
         GROUP BY category ORDER BY category"
    )->fetchAll();
    foreach ($rows as &$row) $row['count'] = (int)$row['count'];
    return ['success' => true, 'categories' => $rows];
}

function getDrink(PDO $pdo, array $r): array
{
    $id  = filter_var($r['drink_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $uid = userIdOf($r);
    if ($id === false) return fail('invalid_drink_id');

    $stmt = $pdo->prepare(
        "SELECT d.drink_id, d.name, d.category, d.glass, d.instructions, d.thumb_url, d.source, d.status,
                (SELECT ROUND(AVG(rt.rating), 1) FROM reviews rt WHERE rt.drink_id = d.drink_id) AS avg_rating,
                (SELECT COUNT(*) FROM reviews rt WHERE rt.drink_id = d.drink_id)               AS review_count
         FROM drinks d
         WHERE d.drink_id = ? AND (d.status = 'published' OR d.created_by = ?)"
    );
    $stmt->execute([$id, $uid]);
    $drink = $stmt->fetch();
    if (!$drink) return fail('drink_not_found');

    $ing = $pdo->prepare(
        'SELECT i.name, di.measure FROM drink_ingredients di
         JOIN ingredients i ON i.ingredient_id = di.ingredient_id
         WHERE di.drink_id = ? ORDER BY di.position'
    );
    $ing->execute([$id]);

    $rev = $pdo->prepare(
        'SELECT u.username, r.rating, r.comment, r.updated_at, (r.user_id = ?) AS mine
         FROM reviews r JOIN users u ON u.user_id = r.user_id
         WHERE r.drink_id = ? ORDER BY r.updated_at DESC LIMIT 20'
    );
    $rev->execute([$uid, $id]);
    $reviews = array_map(static fn (array $x): array => [
        'username' => $x['username'], 'rating' => (int)$x['rating'], 'comment' => $x['comment'],
        'updated_at' => $x['updated_at'], 'mine' => (bool)$x['mine'],
    ], $rev->fetchAll());

    $drink['drink_id']     = (int)$drink['drink_id'];
    $drink['avg_rating']   = $drink['avg_rating'] === null ? null : (float)$drink['avg_rating'];
    $drink['review_count'] = (int)$drink['review_count'];
    $drink['ingredients']  = $ing->fetchAll();
    $drink['reviews']      = $reviews;

    return ['success' => true, 'drink' => $drink];
}

// ---------- pantry (bar-shelf inventory) CRUD ----------

function pantryList(PDO $pdo, array $r): array
{
    $uid = userIdOf($r);
    if ($uid === null) return fail('not_logged_in');
    $stmt = $pdo->prepare(
        'SELECT i.name, p.quantity FROM pantry_items p
         JOIN ingredients i ON i.ingredient_id = p.ingredient_id
         WHERE p.user_id = ? ORDER BY i.name'
    );
    $stmt->execute([$uid]);
    return ['success' => true, 'items' => $stmt->fetchAll()];
}

function pantryAdd(PDO $pdo, array $r): array
{
    $uid = userIdOf($r);
    if ($uid === null) return fail('not_logged_in');
    $name = normalizeIngredient($r['ingredient'] ?? '');
    if (mb_strlen($name) < 2) return fail('invalid_ingredient');
    $qty = cleanText($r['quantity'] ?? '', 40);

    $iid = ingredientId($pdo, $name);

    $exists = $pdo->prepare('SELECT 1 FROM pantry_items WHERE user_id = ? AND ingredient_id = ?');
    $exists->execute([$uid, $iid]);
    if (!$exists->fetchColumn()) {
        $count = $pdo->prepare('SELECT COUNT(*) FROM pantry_items WHERE user_id = ?');
        $count->execute([$uid]);
        if ((int)$count->fetchColumn() >= PANTRY_MAX_ITEMS) return fail('pantry_full');
    }

    $pdo->prepare(
        'INSERT INTO pantry_items (user_id, ingredient_id, quantity) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
    )->execute([$uid, $iid, $qty === '' ? null : $qty]);

    return ['success' => true, 'ingredient' => $name];
}

function pantryUpdate(PDO $pdo, array $r): array
{
    $uid = userIdOf($r);
    if ($uid === null) return fail('not_logged_in');
    $iid = findIngredientId($pdo, normalizeIngredient($r['ingredient'] ?? ''));
    if ($iid === null) return fail('not_in_pantry');
    $qty = cleanText($r['quantity'] ?? '', 40);

    $stmt = $pdo->prepare('UPDATE pantry_items SET quantity = ? WHERE user_id = ? AND ingredient_id = ?');
    $stmt->execute([$qty === '' ? null : $qty, $uid, $iid]);
    if ($stmt->rowCount() === 0) {
        $chk = $pdo->prepare('SELECT 1 FROM pantry_items WHERE user_id = ? AND ingredient_id = ?');
        $chk->execute([$uid, $iid]);
        if (!$chk->fetchColumn()) return fail('not_in_pantry');
    }
    return ['success' => true];
}

function pantryRemove(PDO $pdo, array $r): array
{
    $uid = userIdOf($r);
    if ($uid === null) return fail('not_logged_in');
    $iid = findIngredientId($pdo, normalizeIngredient($r['ingredient'] ?? ''));
    if ($iid === null) return ['success' => true, 'removed' => 0];

    $stmt = $pdo->prepare('DELETE FROM pantry_items WHERE user_id = ? AND ingredient_id = ?');
    $stmt->execute([$uid, $iid]);
    return ['success' => true, 'removed' => $stmt->rowCount()];
}

// ---------- ingredient-based matching ----------

function matchRecipes(PDO $pdo, array $r): array
{
    $uid = userIdOf($r);
    if ($uid === null) return fail('not_logged_in');
    $limit = intIn($r['limit'] ?? 12, 1, 50, 12);

    // "Have" set = the selected ingredients if the caller sent any, else the whole pantry.
    $names = [];
    if (isset($r['ingredients']) && is_array($r['ingredients'])) {
        $names = array_values(array_unique(array_filter(
            array_map('normalizeIngredient', $r['ingredients']), static fn (string $n): bool => $n !== ''
        )));
    }
    if ($names) {
        $in = implode(',', array_fill(0, count($names), '?'));
        $stmt = $pdo->prepare("SELECT ingredient_id FROM ingredients WHERE name IN ($in)");
        $stmt->execute($names);
    } else {
        $stmt = $pdo->prepare('SELECT ingredient_id FROM pantry_items WHERE user_id = ?');
        $stmt->execute([$uid]);
    }
    $have = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (!$have) return ['success' => true, 'matches' => []];

    $ph = implode(',', array_fill(0, count($have), '?'));
    $stmt = $pdo->prepare(
        "SELECT d.drink_id, d.name, d.thumb_url,
                COUNT(*) AS total, SUM(di.ingredient_id IN ($ph)) AS have
         FROM drinks d
         JOIN drink_ingredients di ON di.drink_id = d.drink_id
         WHERE d.status = 'published' OR d.created_by = ?
         GROUP BY d.drink_id, d.name, d.thumb_url
         HAVING have > 0
         ORDER BY (have = total) DESC, have / total DESC, have DESC, d.name
         LIMIT $limit"
    );
    $stmt->execute([...$have, $uid]);
    $rows = $stmt->fetchAll();
    if (!$rows) return ['success' => true, 'matches' => []];

    // What is still missing for each result
    $ids = array_map(static fn (array $x): int => (int)$x['drink_id'], $rows);
    $idPh = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT di.drink_id, i.name FROM drink_ingredients di
         JOIN ingredients i ON i.ingredient_id = di.ingredient_id
         WHERE di.drink_id IN ($idPh) AND di.ingredient_id NOT IN ($ph)
         ORDER BY di.position"
    );
    $stmt->execute([...$ids, ...$have]);
    $missing = [];
    foreach ($stmt->fetchAll() as $m) $missing[(int)$m['drink_id']][] = $m['name'];

    $matches = array_map(static function (array $x) use ($missing): array {
        $id = (int)$x['drink_id'];
        return [
            'drink_id'  => $id,
            'name'      => $x['name'],
            'thumb_url' => $x['thumb_url'],
            'have'      => (int)$x['have'],
            'total'     => (int)$x['total'],
            'missing'   => $missing[$id] ?? [],
            'can_make'  => empty($missing[$id]),
        ];
    }, $rows);

    return ['success' => true, 'matches' => $matches];
}

// ---------- custom recipes & reviews ----------

function submitRecipe(PDO $pdo, array $r): array
{
    $uid = userIdOf($r);
    if ($uid === null) return fail('not_logged_in');

    $name = cleanText($r['name'] ?? '', 100);
    $instructions = cleanText($r['instructions'] ?? '', 2000);
    if (mb_strlen($name) < 3 || mb_strlen($instructions) < 10) return fail('missing_fields');

    $ingredients = parseIngredients($r['ingredients'] ?? null);
    if (!$ingredients) return fail('invalid_ingredients');

    // The site is non-alcoholic only. "ginger beer" and "root beer" are fine.
    $scan = preg_replace('/\b(ginger|root) beer\b/u', '', mb_strtolower($name . ' ' . implode(' ', array_keys($ingredients)))) ?? '';
    if (preg_match(ALCOHOL_RE, $scan)) return fail('alcohol_not_allowed');

    // Same recipe delivered twice (e.g. two consumers on the same exchange) must not duplicate.
    $dupe = $pdo->prepare("SELECT drink_id FROM drinks WHERE created_by = ? AND source = 'user' AND name = ? LIMIT 1");
    $dupe->execute([$uid, $name]);
    if (($existing = $dupe->fetchColumn()) !== false) {
        return ['success' => true, 'drink_id' => (int)$existing, 'status' => 'pending', 'duplicate' => true];
    }

    $drinkId = inTransaction($pdo, function () use ($pdo, $uid, $r, $name, $instructions, $ingredients): int {
        $pdo->prepare(
            "INSERT INTO drinks (external_id, name, category, glass, instructions, source, status, created_by)
             VALUES (NULL, ?, ?, ?, ?, 'user', 'pending', ?)"
        )->execute([
            $name,
            cleanText($r['category'] ?? '', 60) ?: null,
            cleanText($r['glass'] ?? '', 60) ?: null,
            $instructions,
            $uid,
        ]);
        $id = (int)$pdo->lastInsertId();
        saveDrinkIngredients($pdo, $id, $ingredients);
        return $id;
    });

    return ['success' => true, 'drink_id' => $drinkId, 'status' => 'pending'];
}

function submitReview(PDO $pdo, array $r): array
{
    $uid = userIdOf($r);
    if ($uid === null) return fail('not_logged_in');
    $drinkId = filter_var($r['drink_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $rating  = filter_var($r['rating'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
    if ($drinkId === false) return fail('invalid_drink_id');
    if ($rating === false)  return fail('invalid_rating');
    $comment = cleanText($r['comment'] ?? '', 1000);

    $vis = $pdo->prepare("SELECT 1 FROM drinks WHERE drink_id = ? AND (status = 'published' OR created_by = ?)");
    $vis->execute([$drinkId, $uid]);
    if (!$vis->fetchColumn()) return fail('drink_not_found');

    $pdo->prepare(
        'INSERT INTO reviews (drink_id, user_id, rating, comment) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment)'
    )->execute([$drinkId, $uid, $rating, $comment === '' ? null : $comment]);

    return ['success' => true];
}

function deleteReview(PDO $pdo, array $r): array
{
    $uid = userIdOf($r);
    if ($uid === null) return fail('not_logged_in');
    $drinkId = filter_var($r['drink_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($drinkId === false) return fail('invalid_drink_id');

    $stmt = $pdo->prepare('DELETE FROM reviews WHERE drink_id = ? AND user_id = ?');
    $stmt->execute([$drinkId, $uid]);
    return ['success' => true, 'removed' => $stmt->rowCount()];
}
