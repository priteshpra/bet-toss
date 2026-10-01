FROM php:8.2-cli

WORKDIR /app
COPY . /app
RUN mkdir -p data

EXPOSE 10000
CMD ["sh", "-c", "php watcher.php & exec php -S 0.0.0.0:${PORT:-10000} router.php"]
