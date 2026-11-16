-- Milestone 4 schema: drinks, ingredients, pantry, reviews.
-- Run once against the cluster (any Galera node): mysql -u root -p chefs_Db < schema_m4.sql
--
-- Notes
--  * Every table is InnoDB with a primary key, which Galera requires.
--  * users.user_id must match the type used for the *user_id / created_by columns below.
--    Check with:  SHOW CREATE TABLE users;   and change INT to INT UNSIGNED if needed.
--  * Ingredient names are stored lowercase and trimmed (see normalizeIngredient() in drinks_api.php).

CREATE TABLE IF NOT EXISTS ingredients (
    ingredient_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(100) NOT NULL,
    PRIMARY KEY (ingredient_id),
    UNIQUE KEY uq_ingredient_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS drinks (
    drink_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    external_id   VARCHAR(20)  NULL,              -- TheCocktailDB idDrink; NULL for user recipes
    name          VARCHAR(150) NOT NULL,
    category      VARCHAR(60)  NULL,
    glass         VARCHAR(60)  NULL,
    instructions  TEXT         NULL,
    thumb_url     VARCHAR(255) NULL,
    source        ENUM('cocktaildb','user') NOT NULL DEFAULT 'cocktaildb',
    status        ENUM('published','pending','rejected') NOT NULL DEFAULT 'published',
    created_by    INT NULL,                       -- users.user_id for user recipes
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (drink_id),
    UNIQUE KEY uq_drinks_external (external_id),  -- NULLs may repeat, so user recipes are fine
    KEY idx_drinks_name (name),
    KEY idx_drinks_category (category),
    KEY idx_drinks_owner (created_by, status),
    CONSTRAINT fk_drinks_user FOREIGN KEY (created_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS drink_ingredients (
    drink_id      INT UNSIGNED NOT NULL,
    ingredient_id INT UNSIGNED NOT NULL,
    measure       VARCHAR(60)  NULL,
    position      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (drink_id, ingredient_id),
    KEY idx_di_ingredient (ingredient_id),        -- drives ingredient matching
    CONSTRAINT fk_di_drink      FOREIGN KEY (drink_id)      REFERENCES drinks (drink_id)           ON DELETE CASCADE,
    CONSTRAINT fk_di_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients (ingredient_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pantry_items (
    user_id       INT NOT NULL,
    ingredient_id INT UNSIGNED NOT NULL,
    quantity      VARCHAR(40) NULL,
    added_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, ingredient_id),
    KEY idx_pantry_ingredient (ingredient_id),
    CONSTRAINT fk_pantry_user       FOREIGN KEY (user_id)       REFERENCES users (user_id)             ON DELETE CASCADE,
    CONSTRAINT fk_pantry_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients (ingredient_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reviews (
    review_id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    drink_id   INT UNSIGNED NOT NULL,
    user_id    INT NOT NULL,
    rating     TINYINT UNSIGNED NOT NULL,
    comment    VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (review_id),
    UNIQUE KEY uq_review_once (drink_id, user_id),  -- one review per user per drink; resubmitting edits it
    KEY idx_reviews_user (user_id),
    CONSTRAINT chk_rating CHECK (rating BETWEEN 1 AND 5),
    CONSTRAINT fk_reviews_drink FOREIGN KEY (drink_id) REFERENCES drinks (drink_id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_user  FOREIGN KEY (user_id)  REFERENCES users (user_id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Approve a user-submitted recipe:
--   UPDATE drinks SET status = 'published' WHERE drink_id = ? AND source = 'user';
