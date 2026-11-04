FROM php:8.5-cli-alpine

RUN apk add --no-cache sqlite-dev \
    && docker-php-ext-install pdo_sqlite

WORKDIR /app
COPY . .

EXPOSE 8080 9000

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/router.php"]
