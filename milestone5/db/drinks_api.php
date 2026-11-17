<?php
// drinks_api.php - Milestone 4 handlers. Loaded by users_auth.php, so both
// worker.php and amqp_comsumer.php pick these actions up.
declare(strict_types=1);

const DRINK_MAX_INGREDIENTS = 15;
const PANTRY_MAX_ITEMS      = 50;
const ALCOHOL_RE = '/\b(vodka|rum|gin|whiskey|whisky|bourbon|tequila|brandy|cognac|beer|wine|champagne|prosecco|liqueur|schnapps|vermouth|absinthe|mezcal|sake|scotch)\b/u';

/** @return array<string,mixed>|null null = not a drink action */
function processDrinkRequest(PDO $pdo, array $r): ?array
{
    return match (strtolower((string)($r['action'] ?? ''))) {
        'upsert_drink'        => upsertDrink($pdo, $r),
        'list_drinks'         => listDrinks($pdo, $r),
        'list_categories'     => listCategories($pdo),
        'get_drink'           => getDrink($pdo, $r),
        'random_drinks'       => randomDrinks($pdo, $r),
        'suggest_drinks'      => suggestDrinks($pdo, $r),
        'suggest_ingredients' => suggestIngredients($pdo, $r),
        'pantry_list'         => pantryList($pdo, $r),
        'pantry_add'          => pantryAdd($pdo, $r),
        'pantry_update'       => pantryUpdate($pdo, $r),
        'pantry_remove'       => pantryRemove($pdo, $r),
        'match_recipes'       => matchRecipes($pdo, $r),
        'submit_recipe'       => submitRecipe($pdo, $r),
        'submit_review'       => submitReview($pdo, $r),
        'delete_review'       => deleteReview($pdo, $r),
        default               => null,
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
    if ($u === '') return null;
    if (!str_starts_with($u, 'https://')) return null;
    return filter_var($u, FILTER_VALIDATE_URL) !== false ? $u : null;
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
        throw $e;
    }
}

// Rule-based SQL clause mapping the 8 MariaDB categories + drink/ingredient keywords to the 6 spec tags
function tagSqlCondition(string $tag): ?string
{
    return match (mb_strtolower(trim($tag), 'UTF-8')) {
        'iced' => "(d.name LIKE '%ice%' OR d.name LIKE '%cold%' OR d.name LIKE '%cooler%' OR d.name LIKE '% punch%' OR d.name LIKE '%ade%' OR d.category IN ('Punch / Party Drink', 'Soft Drink') OR EXISTS (SELECT 1 FROM drink_ingredients di JOIN ingredients i ON i.ingredient_id = di.ingredient_id WHERE di.drink_id = d.drink_id AND i.name LIKE '%ice%'))",
        'hot' => "(d.category IN ('Cocoa', 'Coffee / Tea') AND d.name NOT LIKE '%iced%' AND d.name NOT LIKE '%cold%' OR d.name LIKE '%hot%' OR d.name LIKE '%warm%' OR d.name LIKE '%masala%' OR d.name LIKE '%chai%' OR d.name LIKE '%tea%' OR d.name LIKE '%coffee%' OR d.name LIKE '%chocolate%')",
        'smoothie' => "(d.category = 'Shake' OR d.name LIKE '%smoothie%' OR d.name LIKE '%shake%' OR d.name LIKE '%frappe%' OR d.name LIKE '%blend%' OR d.name LIKE '%lassi%' OR EXISTS (SELECT 1 FROM drink_ingredients di JOIN ingredients i ON i.ingredient_id = di.ingredient_id WHERE di.drink_id = d.drink_id AND (i.name LIKE '%yoghurt%' OR i.name LIKE '%yogurt%' OR i.name LIKE '%banana%')))",
        'dessert' => "(d.category IN ('Cocoa', 'Shake') OR d.name LIKE '%chocolate%' OR d.name LIKE '%cream%' OR d.name LIKE '%sweet%' OR d.name LIKE '%float%' OR d.name LIKE '%vanilla%' OR d.name LIKE '%caramel%' OR EXISTS (SELECT 1 FROM drink_ingredients di JOIN ingredients i ON i.ingredient_id = di.ingredient_id WHERE di.drink_id = d.drink_id AND (i.name LIKE '%cream%' OR i.name LIKE '%chocolate%' OR i.name LIKE '%syrup%' OR i.name LIKE '%milk%')))",
        'healthy' => "(d.name LIKE '%fruit%' OR d.name LIKE '%juice%' OR d.name LIKE '%berry%' OR d.name LIKE '%lemon%' OR d.name LIKE '%orange%' OR d.name LIKE '%tomato%' OR d.name LIKE '%grape%' OR d.name LIKE '%ginger%' OR EXISTS (SELECT 1 FROM drink_ingredients di JOIN ingredients i ON i.ingredient_id = di.ingredient_id WHERE di.drink_id = d.drink_id AND (i.name LIKE '%juice%' OR i.name LIKE '%lemon%' OR i.name LIKE '%lime%' OR i.name LIKE '%orange%' OR i.name LIKE '%ginger%' OR i.name LIKE '%apple%' OR i.name LIKE '%carrot%' OR i.name LIKE '%mint%')))",
        'matcha' => "(d.name LIKE '%matcha%' OR d.name LIKE '%green tea%' OR d.name LIKE '%tea%' OR d.category = 'Coffee / Tea' OR EXISTS (SELECT 1 FROM drink_ingredients di JOIN ingredients i ON i.ingredient_id = di.ingredient_id WHERE di.drink_id = d.drink_id AND (i.name LIKE '%matcha%' OR i.name LIKE '%tea%')))",
        default => null,
    };
}

// ---------- Phase 0: random_drinks & suggest_* ----------

function randomDrinks(PDO $pdo, array $r): array
{
    $limit = intIn($r['limit'] ?? 6, 1, 20, 6);
    $img   = ($r['with_image'] ?? false) === true ? ' AND thumb_url IS NOT NULL' : '';
    $rows  = $pdo->query(
        "SELECT drink_id, name, category, thumb_url FROM drinks
         WHERE status = 'published'$img ORDER BY RAND() LIMIT $limit"
    )->fetchAll();
    foreach ($rows as &$d) $d['drink_id'] = (int)$d['drink_id'];
    return ['success' => true, 'drinks' => $rows];
}

function suggestDrinks(PDO $pdo, array $r): array
{
    $q = cleanText($r['q'] ?? '', 60);
    if (mb_strlen($q) < 2) return ['success' => true, 'suggestions' => []];
    $esc  = addcslashes($q, '%_\\');
    $stmt = $pdo->prepare(
        "SELECT drink_id, name FROM drinks
         WHERE status = 'published' AND name LIKE ?
         ORDER BY (name LIKE ?) DESC, name LIMIT 8"
    );
    $stmt->execute(['%' . $esc . '%', $esc . '%']);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$d) $d['drink_id'] = (int)$d['drink_id'];
    return ['success' => true, 'suggestions' => $rows];
}

function suggestIngredients(PDO $pdo, array $r): array
{
    $q = normalizeIngredient($r['q'] ?? '');
    if (mb_strlen($q) < 2) return ['success' => true, 'suggestions' => []];
    $esc  = addcslashes($q, '%_\\');
    $stmt = $pdo->prepare(
        "SELECT i.name FROM ingredients i
         WHERE i.name LIKE ?
           AND EXISTS (SELECT 1 FROM drink_ingredients di WHERE di.ingredient_id = i.ingredient_id)
         ORDER BY (i.name LIKE ?) DESC, i.name LIMIT 8"
    );
    $stmt->execute(['%' . $esc . '%', $esc . '%']);
    return ['success' => true, 'suggestions' => $stmt->fetchAll(PDO::FETCH_COLUMN)];
}

// ---------- ingestion ----------

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
    $tag      = cleanText($r['tag'] ?? '', 30);
    $limit    = intIn($r['limit'] ?? 24, 1, 100, 24);
    $offset   = intIn($r['offset'] ?? 0, 0, 100000, 0);

    $where  = ["(d.status = 'published' OR d.created_by = ?)"];
    $params = [$uid];
    if ($q !== '') {
        $esc = '%' . addcslashes($q, '%_\\') . '%';
        $where[] = "(d.name LIKE ? OR d.category LIKE ? OR EXISTS (SELECT 1 FROM drink_ingredients di JOIN ingredients i ON i.ingredient_id = di.ingredient_id WHERE di.drink_id = d.drink_id AND i.name LIKE ?))";
        $params[] = $esc;
        $params[] = $esc;
        $params[] = $esc;
    }
    if ($category !== '') {
        $where[] = 'd.category = ?';
        $params[] = $category;
    }
    if ($tag !== '') {
        $tagCond = tagSqlCondition($tag);
        if ($tagCond !== null) {
            $where[] = $tagCond;
        }
    }

    $stmt = $pdo->prepare(
        "SELECT d.drink_id, d.name, d.category, d.thumb_url, d.source, d.status,
                (SELECT ROUND(AVG(rt.rating), 1) FROM reviews rt WHERE rt.drink_id = d.drink_id) AS avg_rating,
                (SELECT COUNT(*) FROM reviews rt WHERE rt.drink_id = d.drink_id)                AS review_count
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
                (SELECT COUNT(*) FROM reviews rt WHERE rt.drink_id = d.drink_id)                AS review_count
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
        'SELECT r.rating, r.comment, r.updated_at, u.username
         FROM reviews r JOIN users u ON u.user_id = r.user_id
         WHERE r.drink_id = ? ORDER BY r.updated_at DESC LIMIT 50'
    );
    $rev->execute([$id]);

    return [
        'success' => true,
        'drink'   => [
            'drink_id'     => (int)$drink['drink_id'],
            'name'         => $drink['name'],
            'category'     => $drink['category'],
            'glass'        => $drink['glass'],
            'instructions' => $drink['instructions'],
            'thumb_url'    => $drink['thumb_url'],
            'source'       => $drink['source'],
            'status'       => $drink['status'],
            'avg_rating'   => $drink['avg_rating'] === null ? null : (float)$drink['avg_rating'],
            'review_count' => (int)$drink['review_count'],
            'ingredients'  => $ing->fetchAll(),
            'reviews'      => array_map(static fn (array $rv): array => [
                'username'   => $rv['username'],
                'rating'     => (int)$rv['rating'],
                'comment'    => $rv['comment'],
                'updated_at' => $rv['updated_at'],
            ], $rev->fetchAll()),
        ],
    ];
}

// ---------- pantry ----------

function pantryList(PDO $pdo, array $r): array
{
    $uid = userIdOf($r);
    if ($uid === null) return fail('not_logged_in');

    $stmt = $pdo->prepare(
        'SELECT i.name, p.quantity, p.updated_at FROM pantry_items p
         JOIN ingredients i ON i.ingredient_id = p.ingredient_id
         WHERE p.user_id = ? ORDER BY i.name'
    );
    $stmt->execute([$uid]);
    return ['success' => true, 'items' => $stmt->fetchAll()];
}

function pantryAdd(PDO $pdo, array $r): array
{
    $uid  = userIdOf($r);
    $name = normalizeIngredient($r['ingredient'] ?? '');
    $qty  = cleanText($r['quantity'] ?? '', 40);
    if ($uid === null) return fail('not_logged_in');
    if (mb_strlen($name) < 2) return fail('invalid_ingredient');

    $cnt = $pdo->prepare('SELECT COUNT(*) FROM pantry_items WHERE user_id = ?');
    $cnt->execute([$uid]);
    if ((int)$cnt->fetchColumn() >= PANTRY_MAX_ITEMS) return fail('pantry_full');

    $iid = ingredientId($pdo, $name);
    $pdo->prepare(
        'INSERT INTO pantry_items (user_id, ingredient_id, quantity) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
    )->execute([$uid, $iid, $qty === '' ? null : $qty]);

    return ['success' => true, 'ingredient' => $name, 'quantity' => $qty === '' ? null : $qty];
}

function pantryUpdate(PDO $pdo, array $r): array
{
    $uid  = userIdOf($r);
    $name = normalizeIngredient($r['ingredient'] ?? '');
    $qty  = cleanText($r['quantity'] ?? '', 40);
    if ($uid === null) return fail('not_logged_in');
    if ($name === '' || ($iid = findIngredientId($pdo, $name)) === null) return fail('item_not_in_pantry');

    $stmt = $pdo->prepare('UPDATE pantry_items SET quantity = ? WHERE user_id = ? AND ingredient_id = ?');
    $stmt->execute([$qty === '' ? null : $qty, $uid, $iid]);
    return ['success' => true, 'ingredient' => $name, 'quantity' => $qty === '' ? null : $qty];
}

function pantryRemove(PDO $pdo, array $r): array
{
    $uid  = userIdOf($r);
    $name = normalizeIngredient($r['ingredient'] ?? '');
    if ($uid === null) return fail('not_logged_in');
    if ($name === '' || ($iid = findIngredientId($pdo, $name)) === null) return ['success' => true, 'removed' => 0];

    $stmt = $pdo->prepare('DELETE FROM pantry_items WHERE user_id = ? AND ingredient_id = ?');
    $stmt->execute([$uid, $iid]);
    return ['success' => true, 'removed' => $stmt->rowCount()];
}

function matchRecipes(PDO $pdo, array $r): array
{
    $uid   = userIdOf($r);
    $limit = intIn($r['limit'] ?? 12, 1, 50, 12);
    if ($uid === null) return fail('not_logged_in');

    if (isset($r['ingredients']) && is_array($r['ingredients'])) {
        $names = array_values(array_unique(array_filter(array_map(normalizeIngredient(...), $r['ingredients']))));
        if (!$names) return ['success' => true, 'matches' => []];
        $ph   = implode(',', array_fill(0, count($names), '?'));
        $stmt = $pdo->prepare("SELECT ingredient_id FROM ingredients WHERE name IN ($ph)");
        $stmt->execute($names);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } else {
        $stmt = $pdo->prepare('SELECT ingredient_id FROM pantry_items WHERE user_id = ?');
        $stmt->execute([$uid]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    if (!$ids) return ['success' => true, 'matches' => []];

    $in   = implode(',', $ids);
    $rows = $pdo->query(
        "SELECT d.drink_id, d.name, d.category, d.thumb_url,
                SUM(di.ingredient_id IN ($in)) AS have_cnt,
                COUNT(*)                       AS total_cnt
         FROM drinks d
         JOIN drink_ingredients di ON di.drink_id = d.drink_id
         WHERE d.status = 'published'
         GROUP BY d.drink_id
         HAVING have_cnt > 0
         ORDER BY (have_cnt / total_cnt) DESC, (total_cnt - have_cnt) ASC, d.name
         LIMIT $limit"
    )->fetchAll();

    $missing = $pdo->prepare(
        "SELECT i.name FROM drink_ingredients di
         JOIN ingredients i ON i.ingredient_id = di.ingredient_id
         WHERE di.drink_id = ? AND di.ingredient_id NOT IN ($in)
         ORDER BY di.position"
    );

    $matches = array_map(static function (array $x) use ($missing): array {
        $missing->execute([(int)$x['drink_id']]);
        $have  = (int)$x['have_cnt'];
        $total = (int)$x['total_cnt'];
        return [
            'drink_id'  => (int)$x['drink_id'],
            'name'      => $x['name'],
            'category'  => $x['category'],
            'thumb_url' => $x['thumb_url'],
            'have'      => $have,
            'total'     => $total,
            'can_make'  => $have === $total,
            'missing'   => $missing->fetchAll(PDO::FETCH_COLUMN),
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

    $scan = preg_replace('/\b(ginger|root) beer\b/u', '', mb_strtolower($name . ' ' . implode(' ', array_keys($ingredients)))) ?? '';
    if (preg_match(ALCOHOL_RE, $scan)) return fail('alcohol_not_allowed');

    $dupe = $pdo->prepare("SELECT drink_id FROM drinks WHERE created_by = ? AND source = 'user' AND name = ? LIMIT 1");
    $dupe->execute([$uid, $name]);
    if (($existing = $dupe->fetchColumn()) !== false) {
        return ['success' => true, 'drink_id' => (int)$existing, 'status' => 'pending', 'duplicate' => true];
    }

    $thumb = thumbOrNull($r['thumb_url'] ?? '');

    $drinkId = inTransaction($pdo, function () use ($pdo, $uid, $r, $name, $instructions, $ingredients, $thumb): int {
        $pdo->prepare(
            "INSERT INTO drinks (external_id, name, category, glass, instructions, thumb_url, source, status, created_by)
             VALUES (NULL, ?, ?, ?, ?, ?, 'user', 'pending', ?)"
        )->execute([
            $name,
            cleanText($r['category'] ?? '', 60) ?: null,
            cleanText($r['glass'] ?? '', 60) ?: null,
            $instructions,
            $thumb,
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
