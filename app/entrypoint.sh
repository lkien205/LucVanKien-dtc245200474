#!/bin/sh
set -eu

touch /var/log/app/php-error.log

exec apache2-foreground
