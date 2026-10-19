FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends dovecot-imapd libonig-dev libxml2-dev ca-certificates openssl \
    && docker-php-ext-install pdo_mysql mbstring pcntl dom \
    && useradd -m sample
COPY dovecot.conf /etc/dovecot/dovecot.conf
COPY passwd /etc/dovecot/sample-passwd
WORKDIR /code
