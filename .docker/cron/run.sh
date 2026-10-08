#!/bin/sh
# Executa um job PHP com as variáveis de ambiente do container
# (o crond não as repassa) e manda a saída para o log do container.
. /tmp/cron.env
cd /var/www && exec php "$@" >> /proc/1/fd/1 2>> /proc/1/fd/2
