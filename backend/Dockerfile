FROM php:8.3-cli-bookworm

RUN apt-get -o Acquire::ForceIPv4=true update \
    && apt-get -o Acquire::ForceIPv4=true install -y --no-install-recommends \
       libpq-dev libjpeg62-turbo-dev libpng-dev libfreetype6-dev git unzip curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_pgsql gd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/backend

COPY . .

# Never bake local .env into production image.
RUN rm -f .env

RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

EXPOSE 9000

CMD ["sh", "-lc", "test -f .env || cp .env.example .env; [ -n \"$APP_KEY\" ] && sed -i \"s|^APP_KEY=.*|APP_KEY=$APP_KEY|\" .env; [ -n \"$APP_ENV\" ] && sed -i \"s|^APP_ENV=.*|APP_ENV=$APP_ENV|\" .env; [ -n \"$APP_URL\" ] && sed -i \"s|^APP_URL=.*|APP_URL=$APP_URL|\" .env; [ -n \"$ADMIN_PASSWORD\" ] && sed -i \"s|^ADMIN_PASSWORD=.*|ADMIN_PASSWORD=$ADMIN_PASSWORD|\" .env; [ -n \"$ADMIN_OTP_CODE\" ] && sed -i \"s|^ADMIN_OTP_CODE=.*|ADMIN_OTP_CODE=$ADMIN_OTP_CODE|\" .env; mkdir -p storage/framework/cache storage/framework/sessions storage/framework/testing storage/framework/views storage/logs bootstrap/cache; chmod -R ug+rwX storage bootstrap/cache; php artisan serve --host=0.0.0.0 --port=9000"]
