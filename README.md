# oficina-faturamento

[![PR](https://github.com/bregaldahq/oficina-faturamento/actions/workflows/pr.yml/badge.svg?branch=develop)](https://github.com/bregaldahq/oficina-faturamento/actions/workflows/pr.yml)
[![Quality Gate](https://sonarcloud.io/api/project_badges/measure?project=bregaldahq_oficina-faturamento&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=bregaldahq_oficina-faturamento)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=bregaldahq_oficina-faturamento&metric=coverage)](https://sonarcloud.io/component_measures?id=bregaldahq_oficina-faturamento&metric=coverage)

Serviço de **Faturamento** do sistema Oficina Mecânica (Tech Challenge SOAT, Fase 4):
catálogo, estoque, orçamento e pagamento pelo Mercado Pago.

É um dos três microsserviços do sistema, ao lado do `oficina-api` (ordem de serviço e
orquestrador da saga) e do outro serviço de negócio. Cada um tem repositório, banco,
infraestrutura e pipeline próprios.

## O que o serviço faz

Um **orçamento** por ordem de serviço, com a cobrança dentro dele:

```
AWAITING_APPROVAL → APPROVED → PAID
AWAITING_APPROVAL → REJECTED
AWAITING_APPROVAL, APPROVED, PAID → CANCELLED   (compensação pedida pela saga)
FAILED: não pôde ser gerado (item inexistente, estoque insuficiente)
```

| Chega pela fila (comando do orquestrador) | Efeito | Evento |
|---|---|---|
| `billing.generate-budget` | preça os itens pelo catálogo e **reserva as peças** | `budget.generated` ou `budget.failed` |
| `billing.charge` | cria a cobrança no Mercado Pago (Checkout Pro), com prazo | `payment.created` (com o link) |
| `billing.refund-payment` | estorna o pagamento confirmado | `payment.refunded` |
| `billing.cancel-budget` | libera a reserva (ou devolve as peças, se já pagas) e invalida a cobrança em aberto | `budget.cancelled` |

| Chega por HTTP | Efeito | Evento |
|---|---|---|
| webhook de aprovação (`X-Webhook-Token`) | o cliente aprova ou recusa; recusar libera as peças | `budget.approved` ou `budget.rejected` |
| webhook do Mercado Pago (assinado) | consulta o pagamento na API e confirma (as peças saem do estoque) ou registra a recusa | `payment.confirmed` ou `payment.failed` |

**Todo pagamento aprovado faz a OS avançar ou é estornado; nunca fica parado.** Vale o primeiro
que serve (orçamento aprovado, valor igual ao total), inclusive uma nova tentativa depois de uma
recusa. Pagamento em dobro, com valor diferente ou depois do cancelamento volta sozinho. Cancelar
um orçamento já pago também estorna. Estorno feito no painel do Mercado Pago fica registrado.

### Estoque

`disponível = estoque - reservado`. O orçamento reserva ao nascer; recusar ou cancelar libera;
pagar dá baixa; cancelar depois de pago devolve. Cada movimento é um `UPDATE` condicional na
mesma transação do orçamento: se não couber, nada é gravado e a saga recebe
`budget.failed` com `INSUFFICIENT_STOCK`.

### Mercado Pago

- `POST /checkout/preferences` com `external_reference` = id do orçamento, `notification_url` =
  webhook deste serviço e `expires`/`expiration_date_to` = prazo do passo da saga;
- `GET /v1/payments/{id}` depois de cada aviso: **o corpo do webhook nunca é fonte de verdade**;
- `POST /v1/payments/{id}/refunds` para o estorno.

Toda escrita leva `X-Idempotency-Key`. As credenciais ficam no segredo
`oficina/<env>/faturamento/mercadopago`, gravado à mão (credenciais de teste em hml).
`PAYMENT_GATEWAY=fake` troca o Mercado Pago por um adaptador falso nos testes e no e2e.

As chamadas foram conferidas na documentação oficial em 2026-11-16. O formato da assinatura do
webhook (`x-signature`) não apareceu na documentação consultada; segue o que integrações de
terceiros usam, e fica isolado em `src/Infrastructure/Payment/WebhookSignature.php`.

### Consistência (ADR-017)

Orçamento, cobrança, movimentos de estoque, outbox e inbox são gravados numa transação MySQL.
A API publica logo depois de gravar e o worker publica o que ficar para trás. O id de cada
comando fica na inbox, e o par (pagamento, estado) de cada aviso do Mercado Pago também:
reentrega e aviso repetido não repetem efeito.

## Arquitetura

```
API Gateway ─ /api/billing/** ─► NLB interno :81 ─► Pods oficina-faturamento ─► MySQL (RDS próprio)
                                                         │                 └─► Mercado Pago
        SNS oficina-<env>-mensagens ◄─► SQS oficina-<env>-faturamento ─► worker (mesma imagem)
```

- **Entrada HTTP:** rotas sob `/api/billing`, com o mesmo JWT dos outros serviços, revalidado
  aqui.
- **Mensagens:** publica só no tópico e lê só da própria fila. Consome
  `billing.generate-budget`, `billing.charge`, `billing.cancel-budget`, `billing.refund-payment`.
- **Banco:** MySQL (RDS próprio), só deste serviço. Nenhum serviço acessa o banco de outro.
- **Código:** PHP 8.2 sem framework, em camadas (`Domain`, `Application`, `Infrastructure`,
  `Presentation`), como o `oficina-api`.

O contrato entre os serviços (rotas, mensagens, saga, segredos, parâmetros) é o
`docs/fase-4/CONTRATOS.md` do repositório `oficina-api`. As decisões estão nas ADRs 011 a 017,
no mesmo lugar.

## Estrutura

| Pasta | O que tem |
|---|---|
| `src/`, `public/`, `bin/worker.php` | a API e o worker |
| `migrations/`, `bin/migrate.php` | o schema do banco próprio e o runner |
| `tests/`, `features/` | testes unitários, de integração e o BDD |
| `deploy/` | manifests kustomize (`base` e overlays `hml` e `prod`) |
| `infra/` | Terraform do que é só deste serviço: ECR, fila e DLQ, assinatura do tópico, role dos pods e o banco |
| `.github/workflows/` | `pr.yml` e `deploy.yml` |
| `swagger.yaml`, `docs/` | a API e o Swagger UI (`/api/billing/docs`) |

## Rodar e testar

A máquina de desenvolvimento não precisa de PHP: tudo roda em container.

```bash
make install    # dependências
make test       # testes unitários e de integração (SQLite em memória)
make bdd        # o fluxo completo em Behat (features/faturamento.feature)
make coverage   # testes com cobertura; falha abaixo de 80%
make stan       # PHPStan nível 8
make lint       # estilo (PSR-12)
```

As migrations ficam em `migrations/` e rodam pelo `bin/migrate.php` (no cluster, pelo Job
`oficina-faturamento-migrate`, antes do rollout). `002_catalogo_demo.sql` traz o catálogo com os
mesmos ids da Fase 3 e é pulado em produção.

O ponta a ponta contra MySQL e LocalStack roda a partir do repositório principal:
`bin/ws e2e --fase4`.

## Qualidade

- **178 testes, 92,8% de cobertura** de linhas de `src/` (medido em 2026-11-16). Os testes de
  integração rodam o repositório PDO de produção contra SQLite; `tests/Integration/SchemaTest.php`
  confere que o schema dos testes é o da migration.
- **BDD:** 7 cenários em `features/faturamento.feature`: fluxo completo, estoque insuficiente,
  recusa do cliente, compensação do reparo que falhou, prazo vencido com pagamento já confirmado,
  pagamento depois do cancelamento e aviso
  repetido do Mercado Pago.

- Cobertura mínima de **80%** das linhas de `src/`, exigida no `pr.yml` e de novo antes do
  deploy.
- SonarCloud com quality gate no PR (projeto `bregaldahq_oficina-faturamento`).
- `main` e `develop` protegidas: só entram por PR com as checagens verdes.

### Evidências

- **Cobertura por PR:** cada run do [`pr.yml`](https://github.com/bregaldahq/oficina-faturamento/actions/workflows/pr.yml)
  escreve a linha de cobertura no resumo do run e guarda o `coverage.xml` como artefato
  (`cobertura`); abaixo de 80% o job falha.
- **Qualidade:** [SonarCloud](https://sonarcloud.io/summary/new_code?id=bregaldahq_oficina-faturamento), com o
  quality gate como checagem obrigatória do PR.
- **Deploy:** cada run do [`deploy.yml`](https://github.com/bregaldahq/oficina-faturamento/actions/workflows/deploy.yml)
  roda os testes antes, faz o smoke depois e marca o deploy no New Relic.

## CI/CD

| Workflow | Quando | O que faz |
|---|---|---|
| `pr.yml` | PR para `develop` ou `main` | estilo, PHPStan, testes com cobertura, Sonar, build da imagem, `kustomize build`, `terraform fmt` e `validate` |
| `deploy.yml` | push em `develop` (hml) ou `main` (prod) | testes, `terraform apply` do `infra/`, imagem no ECR, `kubectl apply -k`, rollout e smoke |
| `destroy.yml` | só à mão (*Actions → Destroy*), digitando o ambiente | `terraform destroy` do `infra/`, preservando o ECR; roda antes do destroy do cluster e da fundação |

Autenticação na AWS por OIDC, sem access key. Segredos de repositório: `AWS_ROLE_ARN`,
`TF_STATE_BUCKET`, `NEW_RELIC_LICENSE_KEY`, `NEW_RELIC_API_KEY`, `SONAR_TOKEN`.

Antes do primeiro deploy precisam existir a fundação, o cluster e o gateway (ordem de apply do
contrato).
