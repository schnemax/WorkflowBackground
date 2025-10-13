FROM php:8.2-cli
# PDO MySQL aktivieren (nutzt mysqlnd, keine extra Libs nötig)
RUN docker-php-ext-install pdo_mysql
WORKDIR /app
# (optional – wenn du weiterhin per volume mountest, kannst du COPY weglassen)
# COPY public /app/public
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
