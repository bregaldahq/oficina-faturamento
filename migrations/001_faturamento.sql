-- ============================================================
-- 001_faturamento.sql
-- Schema do serviço de Faturamento (docs/fase-4/CONTRATOS.md §7, no oficina-api).
-- Banco próprio do serviço: nenhum outro serviço lê ou escreve aqui (ADR-014).
--
-- Mesmas regras das migrations da fundação: idempotente, sem DELIMITER, sem USE, sem
-- CREATE DATABASE (o banco é criado pelo Terraform do infra/). Um statement por `;`.
-- O runner (bin/migrate.php) registra a versão em schema_migrations.
-- ============================================================

-- Catálogo de serviços: o preço de referência do orçamento.
CREATE TABLE IF NOT EXISTS service_catalog (
    id                     VARCHAR(36)    NOT NULL,
    description            VARCHAR(255)   NOT NULL,
    base_price             DECIMAL(10, 2) NOT NULL,
    estimated_time_minutes INT            NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Estoque de peças. reserved_quantity é o que está preso a orçamentos em aberto; o disponível
-- é stock_quantity - reserved_quantity. Os CHECKs são a última linha de defesa: o repositório
-- já só reserva o que cabe.
CREATE TABLE IF NOT EXISTS parts_inventory (
    id                VARCHAR(36)    NOT NULL,
    description       VARCHAR(255)   NOT NULL,
    price             DECIMAL(10, 2) NOT NULL,
    stock_quantity    INT            NOT NULL DEFAULT 0,
    reserved_quantity INT            NOT NULL DEFAULT 0,
    version           INT            NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    CONSTRAINT chk_parts_stock_non_negative CHECK (stock_quantity >= 0),
    CONSTRAINT chk_parts_reserved_range CHECK (reserved_quantity >= 0 AND reserved_quantity <= stock_quantity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Orçamento: um por ordem de serviço.
CREATE TABLE IF NOT EXISTS budgets (
    id             VARCHAR(36)    NOT NULL,
    os_id          VARCHAR(36)    NOT NULL,
    saga_id        VARCHAR(36)    NOT NULL,
    correlation_id VARCHAR(64)    NOT NULL DEFAULT '',
    customer_id    VARCHAR(36)    NOT NULL,
    customer_name  VARCHAR(255)   NOT NULL DEFAULT '',
    customer_email VARCHAR(255)   NOT NULL DEFAULT '',
    status         VARCHAR(30)    NOT NULL,
    total_amount   DECIMAL(10, 2) NOT NULL DEFAULT 0,
    failure_reason VARCHAR(255)   NULL,
    version        INT            NOT NULL DEFAULT 1,
    created_at     DATETIME(3)    NOT NULL,
    updated_at     DATETIME(3)    NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_budgets_os (os_id),
    KEY idx_budgets_customer (customer_id),
    KEY idx_budgets_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Itens do orçamento, com o preço congelado na geração.
CREATE TABLE IF NOT EXISTS budget_items (
    id          VARCHAR(36)    NOT NULL,
    budget_id   VARCHAR(36)    NOT NULL,
    position    INT            NOT NULL,
    kind        VARCHAR(10)    NOT NULL,
    item_id     VARCHAR(36)    NOT NULL,
    description VARCHAR(255)   NOT NULL,
    unit_price  DECIMAL(10, 2) NOT NULL,
    quantity    INT            NOT NULL,
    PRIMARY KEY (id),
    KEY idx_budget_items_budget (budget_id),
    CONSTRAINT fk_budget_items_budget FOREIGN KEY (budget_id) REFERENCES budgets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A cobrança de um orçamento (uma por orçamento).
CREATE TABLE IF NOT EXISTS payments (
    id                  VARCHAR(36)    NOT NULL,
    budget_id           VARCHAR(36)    NOT NULL,
    preference_id       VARCHAR(128)   NOT NULL,
    payment_url         VARCHAR(512)   NOT NULL,
    amount              DECIMAL(10, 2) NOT NULL,
    status              VARCHAR(20)    NOT NULL,
    external_payment_id VARCHAR(64)    NULL,
    failure_reason      VARCHAR(255)   NULL,
    expires_at          DATETIME(3)    NOT NULL,
    created_at          DATETIME(3)    NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_payments_budget (budget_id),
    CONSTRAINT fk_payments_budget FOREIGN KEY (budget_id) REFERENCES budgets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Outbox (ADR-017): mensagens gravadas na transação do estado, publicadas depois.
CREATE TABLE IF NOT EXISTS outbox (
    id           VARCHAR(36) NOT NULL,
    type         VARCHAR(64) NOT NULL,
    body         TEXT        NOT NULL,
    occurred_at  VARCHAR(32) NOT NULL,
    published_at DATETIME(3) NULL,
    PRIMARY KEY (id),
    KEY idx_outbox_pending (published_at, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inbox (ADR-017): mensagens de entrada já tratadas. A chave pode ser o id de uma mensagem da
-- saga ou o par (pagamento, estado) de uma notificação do Mercado Pago.
CREATE TABLE IF NOT EXISTS inbox (
    message_id   VARCHAR(128) NOT NULL,
    processed_at DATETIME(3)  NOT NULL,
    PRIMARY KEY (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
