-- O schema de migrations/001_faturamento.sql em SQLite, para os testes de integração.
-- tests/Integration/SchemaTest.php confere que as tabelas e colunas são as mesmas.
CREATE TABLE service_catalog (
    id                     TEXT    NOT NULL PRIMARY KEY,
    description            TEXT    NOT NULL,
    base_price             NUMERIC NOT NULL,
    estimated_time_minutes INTEGER NOT NULL
);
CREATE TABLE parts_inventory (
    id                TEXT    NOT NULL PRIMARY KEY,
    description       TEXT    NOT NULL,
    price             NUMERIC NOT NULL,
    stock_quantity    INTEGER NOT NULL DEFAULT 0,
    reserved_quantity INTEGER NOT NULL DEFAULT 0,
    version           INTEGER NOT NULL DEFAULT 0,
    CHECK (stock_quantity >= 0),
    CHECK (reserved_quantity >= 0 AND reserved_quantity <= stock_quantity)
);
CREATE TABLE budgets (
    id             TEXT    NOT NULL PRIMARY KEY,
    os_id          TEXT    NOT NULL UNIQUE,
    saga_id        TEXT    NOT NULL,
    correlation_id TEXT    NOT NULL DEFAULT '',
    customer_id    TEXT    NOT NULL,
    customer_name  TEXT    NOT NULL DEFAULT '',
    customer_email TEXT    NOT NULL DEFAULT '',
    status         TEXT    NOT NULL,
    total_amount   NUMERIC NOT NULL DEFAULT 0,
    failure_reason TEXT    NULL,
    version        INTEGER NOT NULL DEFAULT 1,
    created_at     TEXT    NOT NULL,
    updated_at     TEXT    NOT NULL
);
CREATE TABLE budget_items (
    id          TEXT    NOT NULL PRIMARY KEY,
    budget_id   TEXT    NOT NULL REFERENCES budgets (id),
    position    INTEGER NOT NULL,
    kind        TEXT    NOT NULL,
    item_id     TEXT    NOT NULL,
    description TEXT    NOT NULL,
    unit_price  NUMERIC NOT NULL,
    quantity    INTEGER NOT NULL
);
CREATE TABLE payments (
    id                  TEXT    NOT NULL PRIMARY KEY,
    budget_id           TEXT    NOT NULL UNIQUE REFERENCES budgets (id),
    preference_id       TEXT    NOT NULL,
    payment_url         TEXT    NOT NULL,
    amount              NUMERIC NOT NULL,
    status              TEXT    NOT NULL,
    external_payment_id TEXT    NULL,
    failure_reason      TEXT    NULL,
    expires_at          TEXT    NOT NULL,
    created_at          TEXT    NOT NULL
);
CREATE TABLE outbox (
    id           TEXT NOT NULL PRIMARY KEY,
    type         TEXT NOT NULL,
    body         TEXT NOT NULL,
    occurred_at  TEXT NOT NULL,
    published_at TEXT NULL
);
CREATE TABLE inbox (
    message_id   TEXT NOT NULL PRIMARY KEY,
    processed_at TEXT NOT NULL
);
