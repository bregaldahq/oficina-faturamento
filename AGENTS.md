# oficina-faturamento — guia para agentes

Serviço de **Faturamento** da Oficina Mecânica: catálogo, estoque, orçamento e pagamento pelo Mercado Pago. PHP 8.2 puro, sem framework
(ADR-001), nas mesmas camadas do `oficina-api`. Banco: MySQL (RDS próprio).

O sistema tem seis repos; o mapa, as regras entre eles e as ferramentas (`bin/ws`) ficam no
repositório principal `oficina-mecanica`. O contrato entre os repos é
`docs/fase-4/CONTRATOS.md`, no `oficina-api` (ele soma ao `docs/fase-3/CONTRATOS.md`). É
normativo: se divergir daqui, o documento vence.

## Camadas (`src/`)

- **`Domain/`**: `Budget/Budget.php` é o agregado, um orçamento por OS, com a cobrança
  (`Payment`) dentro. Transições nomeadas (`generate`, `failed`, `approve`, `reject`,
  `attachPayment`, `confirmPayment`, `failPayment`, `recordRefund`, `recordLateRefund`,
  `cancel`); **não existe `setStatus`**. Toda mudança que mexe no estoque registra um
  `StockMovement`, e toda que o serviço de OS precisa saber, um evento. `Catalog/` tem o
  serviço e a peça.
- **`Application/`**: portas em `Port/` (o meio de pagamento é `PaymentGatewayInterface`);
  `HandleIncomingMessageUseCase` (comandos da saga), `HandlePaymentNotificationUseCase` (avisos
  do Mercado Pago), `UseCase/Budget/` (consultas e a resposta do cliente).
- **`Infrastructure/`**: `Repository/` (PDO), `Payment/` (Mercado Pago, o falso e a assinatura
  do webhook), `Aws/` (SNS e SQS), `ServiceFactory` (a montagem da API e do worker).
- **`Presentation/`**: `CatalogController`, `BudgetController`, `PaymentWebhookController`.

## Regras que não podem quebrar

- **Estado, estoque e evento na mesma gravação.** Toda mudança passa por
  `BudgetRepositoryInterface::save($budget, $outbox, $inboxMessageId)`. Nunca publique direto
  no tópico nem mexa em `reserved_quantity` fora do repositório.
- **O webhook do Mercado Pago não é fonte de verdade.** O estado vem sempre de
  `PaymentGatewayInterface::getPayment()`. A assinatura é conferida antes de qualquer coisa.
- **Chamada externa repetida não repete efeito.** Cobrança e estorno levam chave de
  idempotência derivada do orçamento ou do pagamento.
- **SQL comum a MySQL e SQLite.** Os testes rodam o repositório de produção em SQLite. Cada
  parâmetro aparece uma vez no SQL (o MySQL, com prepared statement nativo, não aceita nome
  repetido), e quantidades são ligadas com `PDO::PARAM_INT` (no SQLite, número comparado com
  texto é sempre falso).
- **Coluna nova** entra em `migrations/` e em `tests/Support/schema.sqlite.sql`;
  `tests/Integration/SchemaTest.php` reprova se faltar num dos dois.
- **O JSON do envelope é contrato.** `tests/Unit/Domain/MessageTest.php` tem o literal; os
  outros serviços têm o mesmo.

## O que este repo tem

- `src/`: `Domain`, `Application`, `Infrastructure`, `Presentation`. A dependência aponta para
  dentro.
- `public/index.php`: composition root, com **todas as rotas e a matriz de autorização**. Toda
  rota fica sob `/api/billing`. Rota nova entra aqui e no `swagger.yaml`. Rota sem JWT
  (`requireAuth: false`) também precisa de rota dedicada no `apigateway.tf` do
  `oficina-gateway-auth`.
- `deploy/`: kustomize. Os objetos têm o nome do serviço (`oficina-faturamento-config`,
  `oficina-faturamento-secret`, `oficina-faturamento-nginx`), porque os três serviços dividem o namespace.
- `infra/`: Terraform só do que é deste serviço (ECR, fila, DLQ, assinatura, role IRSA e o
  banco). Lê a plataforma por SSM; não lê parâmetro de outro serviço.

## Contratos

- **JWT:** o mesmo token dos outros serviços. `tests/Unit/Infrastructure/Security/JwtContractTest.php`
  tem o token literal; o `JwtProvider` não muda sozinho aqui (use `/mudar-jwt` no repositório
  principal).
- **Mensagens:** envelope e catálogo no §5 do contrato. Este serviço consome
  `billing.generate-budget`, `billing.charge`, `billing.cancel-budget`, `billing.refund-payment`; a lista é `local.consumed_message_types`, em `infra/locals.tf`. Tipo novo
  entra lá, no handler e no catálogo, juntos.
- **Consome (SSM):** `messaging/topic_arn`, `eks/oidc_provider_arn`, `eks/namespace`,
  `eks/cluster_name` e `nlb/faturamento/target_group_arn`.
- **Publica (SSM):** `/oficina/<env>/faturamento/*`, lidos só pelo `deploy.yml` deste repo.
- **Banco:** só este serviço acessa o próprio banco, e ele não acessa o de ninguém.

## Placeholders do deploy

`TARGET_GROUP_ARN_PLACEHOLDER` (`deploy/base/targetgroupbinding.yaml`),
`IRSA_ROLE_ARN_PLACEHOLDER` (`deploy/base/serviceaccount.yaml`) e `APP_IMAGE_PLACEHOLDER`
(`deploy/base/migration-job.yaml`) **ficam como estão**: o `deploy.yml` troca os três por `sed`.

## Validar

- A partir do repositório principal: `bin/ws verify --repo faturamento`.
- Aqui: `make test`, `make bdd`, `make coverage` (falha abaixo de 80% das linhas de `src/`),
  `make stan`, `make lint`.
- Contra MySQL e LocalStack: `bin/ws e2e --fase4`, no repositório principal.
- Sem conta AWS, o teto do `infra/` é `terraform fmt` e `validate` sem backend.

## Convenções

- Branches `feat/…`, `fix/…`, `chore/…`, `ci/…`, com PR para `develop`; `develop` faz deploy em
  hml e `main` em prod.
- Commits em português, sem acento no assunto, Conventional Commits com escopo, só com o nome
  do autor: sem `Co-Authored-By` nem rodapé de ferramenta.
- Código em inglês para os conceitos do domínio (glossário no `AGENTS.md` do `oficina-api`).
- Agente nunca roda `terraform apply`, `kubectl apply`/`delete`, nunca lê `.env` nem valor de
  segredo.
