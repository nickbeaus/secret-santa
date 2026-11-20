# Secret Santa: PHP + Apache + SQLite in one container.
# Everything runs as www-data (Apache listens on 8080), so files in /data are
# always owned by the web server, including ones made by `docker compose exec`.
FROM php:8.3-apache-bookworm

# Links the published image to its GitHub repository (shown on the ghcr.io package page).
LABEL org.opencontainers.image.source="https://github.com/nickbeaus/secret-santa" \
      org.opencontainers.image.description="Secret Santa drawings with private reveal links and texting"

RUN set -eux; \
    a2enmod rewrite; \
    sed -i 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf; \
    # "zz-" so it loads after the image's security.conf, which would otherwise override it.
    printf 'ServerName localhost\nServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-available/zz-hardening.conf; \
    a2enconf zz-hardening; \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    php -m | grep -qx pdo_sqlite; \
    php -r 'exit(defined("PASSWORD_ARGON2ID") ? 0 : 1);'

COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-secret-santa.ini"
COPY docker/apache-site.conf /etc/apache2/sites-available/000-default.conf
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/secret-santa-entrypoint
COPY app/ /app/

RUN mkdir -p /data && chown www-data:www-data /data && chmod 750 /data

WORKDIR /app
USER www-data
EXPOSE 8080

HEALTHCHECK --interval=60s --timeout=5s --start-period=20s \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/index.php") === false ? 1 : 0);'

ENTRYPOINT ["secret-santa-entrypoint"]
CMD ["apache2-foreground"]
