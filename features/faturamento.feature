# language: pt
Funcionalidade: Orçamento e pagamento da ordem de serviço
  Para que a saga da ordem de serviço avance com segurança
  Como serviço de Faturamento
  Quero gerar o orçamento com as peças reservadas, cobrar pelo Mercado Pago
  e desfazer cada efeito quando a saga pedir

  Contexto:
    Dado que o estoque tem 5 pastilhas de freio
    E que o diagnóstico da OS "os-200" pediu 1 troca de óleo e 2 pastilhas

  Cenário: Fluxo completo, do orçamento ao pagamento confirmado
    Quando o orquestrador pede o orçamento da OS "os-200"
    Então o orçamento da OS "os-200" fica "AWAITING_APPROVAL" com total de 558.00
    E o estoque fica com 5 pastilhas, 2 reservadas
    Quando o cliente aprova o orçamento da OS "os-200"
    E o orquestrador pede a cobrança da OS "os-200"
    Então o cliente recebe um link de pagamento
    Quando o cliente paga pelo Mercado Pago
    Então o orçamento da OS "os-200" fica "PAID" com total de 558.00
    E o estoque fica com 3 pastilhas, 0 reservadas
    E o serviço de OS recebe os eventos "budget.generated, budget.approved, payment.created, payment.confirmed"
    E todo evento publicado leva a saga e a OS de origem

  Cenário: Sem estoque, o orçamento falha e nada fica reservado
    Dado que o diagnóstico da OS "os-200" pediu 1 troca de óleo e 6 pastilhas
    Quando o orquestrador pede o orçamento da OS "os-200"
    Então o serviço de OS recebe os eventos "budget.failed"
    E o último evento publicado traz o motivo "INSUFFICIENT_STOCK"
    E o estoque fica com 5 pastilhas, 0 reservadas

  Cenário: O cliente recusa o orçamento e as peças voltam a ficar disponíveis
    Quando o orquestrador pede o orçamento da OS "os-200"
    E o cliente recusa o orçamento da OS "os-200"
    Então o orçamento da OS "os-200" fica "REJECTED" com total de 558.00
    E o estoque fica com 5 pastilhas, 0 reservadas
    E o serviço de OS recebe os eventos "budget.generated, budget.rejected"

  Cenário: O reparo falha depois do pagamento e a saga compensa
    Dado que o cliente já pagou o orçamento da OS "os-200"
    Quando o orquestrador pede o estorno do pagamento da OS "os-200"
    E o orquestrador pede o cancelamento do orçamento da OS "os-200"
    Então o Mercado Pago estornou 1 pagamento
    E o orçamento da OS "os-200" fica "CANCELLED" com total de 558.00
    E o estoque fica com 5 pastilhas, 0 reservadas
    E os últimos eventos publicados são "payment.refunded, budget.cancelled"

  Cenário: O cliente paga depois de o prazo vencer e o dinheiro volta sozinho
    Quando o orquestrador pede o orçamento da OS "os-200"
    E o cliente aprova o orçamento da OS "os-200"
    E o orquestrador pede a cobrança da OS "os-200"
    E o orquestrador pede o cancelamento do orçamento da OS "os-200"
    E o cliente paga pelo Mercado Pago
    Então o Mercado Pago estornou 1 pagamento
    E o estoque fica com 5 pastilhas, 0 reservadas
    E os últimos eventos publicados são "budget.cancelled, payment.refunded"

  Cenário: O Mercado Pago avisa duas vezes e o efeito é um só
    Dado que o cliente já pagou o orçamento da OS "os-200"
    Quando o Mercado Pago repete o aviso do pagamento
    Então o estoque fica com 3 pastilhas, 0 reservadas
    E o evento "payment.confirmed" foi publicado 1 vez

  Cenário: O prazo vence na saga depois de o pagamento ter sido confirmado
    Dado que o cliente já pagou o orçamento da OS "os-200"
    Quando o orquestrador pede o cancelamento do orçamento da OS "os-200"
    Então o Mercado Pago estornou 1 pagamento
    E o orçamento da OS "os-200" fica "CANCELLED" com total de 558.00
    E o estoque fica com 5 pastilhas, 0 reservadas
    E os últimos eventos publicados são "payment.refunded, budget.cancelled"
