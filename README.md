# oficina-faturamento

Serviço de **Faturamento** do sistema Oficina Mecânica (Tech Challenge SOAT, Fase 4):
catálogo, estoque, orçamento e pagamento pelo Mercado Pago.

É um dos três microsserviços do sistema, ao lado do `oficina-api` (ordem de serviço e
orquestrador da saga) e do outro serviço de negócio. Cada um tem repositório, banco,
infraestrutura e pipeline próprios.

> **Estado: esqueleto.** O repo responde `/api/billing/health` e `/api/billing/ready` e já tem
> imagem, manifests, Terraform e pipelines. A regra de negócio entra nas próximas etapas do
> plano da Fase 4.

## Arquitetura

```
API Gateway ─ /api/billing/** ─► NLB interno :81 ─► Pods oficina-faturamento ─► MySQL (RDS próprio)
                                                             │
        SNS oficina-<env>-mensagens ─► SQS oficina-<env>-faturamento ─┘  (worker, próximas etapas)
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
| `src/`, `public/`, `tests/` | o serviço e os testes |
| `deploy/` | manifests kustomize (`base` e overlays `hml` e `prod`) |
| `infra/` | Terraform do que é só deste serviço: ECR, fila e DLQ, assinatura do tópico, role dos pods e o banco |
| `.github/workflows/` | `pr.yml` e `deploy.yml` |
| `swagger.yaml`, `docs/` | a API e o Swagger UI (`/api/billing/docs`) |

## Rodar e testar

A máquina de desenvolvimento não precisa de PHP: tudo roda em container.

```bash
make install    # dependências
make test       # testes
make coverage   # testes com cobertura; falha abaixo de 80%
make stan       # PHPStan nível 8
make lint       # estilo (PSR-12)
```

## Qualidade

- Cobertura mínima de **80%** das linhas de `src/`, exigida no `pr.yml` e de novo antes do
  deploy.
- SonarCloud com quality gate no PR (projeto `bregaldahq_oficina-faturamento`).
- `main` e `develop` protegidas: só entram por PR com as checagens verdes.

<!-- Evidências (badges do Sonar e da cobertura, link do último run) entram aqui quando o
     primeiro pipeline rodar no GitHub. -->

## CI/CD

| Workflow | Quando | O que faz |
|---|---|---|
| `pr.yml` | PR para `develop` ou `main` | estilo, PHPStan, testes com cobertura, Sonar, build da imagem, `kustomize build`, `terraform fmt` e `validate` |
| `deploy.yml` | push em `develop` (hml) ou `main` (prod) | testes, `terraform apply` do `infra/`, imagem no ECR, `kubectl apply -k`, rollout e smoke |

Autenticação na AWS por OIDC, sem access key. Segredos de repositório: `AWS_ROLE_ARN`,
`TF_STATE_BUCKET`, `NEW_RELIC_LICENSE_KEY`, `NEW_RELIC_API_KEY`, `SONAR_TOKEN`.

Antes do primeiro deploy precisam existir a fundação, o cluster e o gateway (ordem de apply do
contrato).
