#!/bin/sh
# Entrypoint da imagem do serviço.
#
# 1) Materializa o .env que a aplicação espera (EnvLoader::loadFile) a partir do ambiente do
#    container (ConfigMap + Secret no Kubernetes). Com .env por bind mount, vira no-op.
# 2) Deixa o agente do New Relic inerte quando não há license key (local e CI): a extensão
#    continua carregada, mas nada é coletado e o daemon não sobe.
set -e

NEW_RELIC_APP_NAME="${NEW_RELIC_APP_NAME:-oficina-faturamento-local}"
NEW_RELIC_DISTRIBUTED_TRACING_ENABLED="${NEW_RELIC_DISTRIBUTED_TRACING_ENABLED:-true}"
export NEW_RELIC_APP_NAME NEW_RELIC_DISTRIBUTED_TRACING_ENABLED

if [ -z "${NEW_RELIC_LICENSE_KEY:-}" ]; then
    NEW_RELIC_ENABLED=false
    NEW_RELIC_MONITOR_MODE=false
    # 3 = nunca tentar iniciar o daemon (sem processo extra, sem log de erro).
    NEW_RELIC_DAEMON_DONT_LAUNCH=3
    export NEW_RELIC_LICENSE_KEY=""
else
    NEW_RELIC_ENABLED=true
    NEW_RELIC_MONITOR_MODE=true
    NEW_RELIC_DAEMON_DONT_LAUNCH=0
fi
export NEW_RELIC_ENABLED NEW_RELIC_MONITOR_MODE NEW_RELIC_DAEMON_DONT_LAUNCH

ENV_FILE="/var/www/html/.env"

if [ ! -f "$ENV_FILE" ]; then
    for var in APP_ENV APP_DEBUG \
               DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD \
               JWT_SECRET JWT_EXPIRATION WEBHOOK_TOKEN \
               AWS_REGION AWS_ENDPOINT_URL WORKER_WAIT_SECONDS MIGRATIONS_PATH MESSAGING_TOPIC_ARN MESSAGING_QUEUE_URL DYNAMODB_TABLE \
               PAYMENT_GATEWAY MP_ACCESS_TOKEN MP_WEBHOOK_SECRET MP_NOTIFICATION_URL MP_USE_SANDBOX_URL \
               NEW_RELIC_APP_NAME NEW_RELIC_LICENSE_KEY; do
        eval "value=\${$var:-}"
        if [ -n "$value" ]; then
            printf '%s=%s\n' "$var" "$value" >> "$ENV_FILE"
        fi
    done
fi

exec "$@"
