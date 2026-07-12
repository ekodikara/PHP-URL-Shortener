FROM php:8.3-apache

# Install the MySQL PDO driver + mod_security (needed to rewrite the Server header)
RUN apt-get update \
    && apt-get install -y --no-install-recommends libapache2-mod-security2 unzip git \
    && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install pdo_mysql

# APCu: shared-memory cache so the ~77k-line adult-domain blocklist is parsed
# once per worker (keyed by mtime) instead of on every redirect / link-create.
RUN pecl install apcu \
    && docker-php-ext-enable apcu \
    && printf 'apc.enabled=1\napc.shm_size=64M\n' > /usr/local/etc/php/conf.d/apcu.ini

# Enable mod_rewrite (clean URLs), mod_headers (spoof headers), mod_security2 (Server header)
RUN a2enmod rewrite headers security2

# Allow .htaccess overrides in the web root
RUN sed -ri 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Server fingerprint SPOOFING — make the stack look like Windows Azure / IIS so
# attackers waste time on the wrong exploits:
#  - PHP's real "X-Powered-By" is suppressed (expose_php Off); a fake ASP.NET one
#    is set in .htaccess via mod_headers.
#  - The Apache "Server" header is overwritten to "Microsoft-IIS/10.0" via
#    mod_security's SecServerSignature (requires ServerTokens Full so there's
#    room to overwrite the string).
RUN printf 'expose_php = Off\n' > /usr/local/etc/php/conf.d/zz-hardening.ini \
    && printf 'ServerTokens Full\nServerSignature Off\nTraceEnable Off\n<IfModule security2_module>\n  SecRuleEngine DetectionOnly\n  SecRequestBodyAccess Off\n  SecResponseBodyAccess Off\n  SecServerSignature "Microsoft-IIS/10.0"\n</IfModule>\n' > /etc/apache2/conf-available/security-hardening.conf \
    && a2enconf security-hardening

WORKDIR /var/www/html

# Composer + PHP dependencies (Stripe SDK). Install deps first for layer caching.
# Pinned composer minor + committed composer.lock → reproducible dependency set.
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock /var/www/html/
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts

# Copy application source
COPY . /var/www/html/

# rename.htaccess holds the rewrite rules; activate it as .htaccess
RUN cp rename.htaccess .htaccess

# Create a writable cache directory owned by the web server
RUN mkdir -p /var/www/html/cache \
    && chown -R www-data:www-data /var/www/html/cache

EXPOSE 80
