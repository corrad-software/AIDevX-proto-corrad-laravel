from php:8.4-fpm-alpine as base

workdir /var/www/html

# Install system dependencies for Laravel, Composer, Node build, and Nginx
run apk add --no-cache \
    nginx \
    supervisor \
    git \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    oniguruma-dev \
    libxml2-dev \
    libzip-dev \
    zlib-dev \
    zip \
    unzip \
    nodejs \
    npm

# Configure PHP extensions required by Laravel
run docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip

# Install Composer
copy --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy application files
copy . /var/www/html

# Install PHP dependencies
run composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# Install and build client (Vue) assets
workdir /var/www/html/client
run npm install && npm run build

# Back to Laravel root for final setup
workdir /var/www/html

# Optimize Laravel
run php artisan config:clear && \
    php artisan route:clear && \
    php artisan view:clear && \
    php artisan config:cache && \
    php artisan route:cache && \
    php artisan view:cache || true

# Ensure proper permissions
run chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache && \
    chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Nginx configuration via BuildKit heredoc
run --mount=type=cache,target=/var/cache/nginx <<'EOF'
cat >/etc/nginx/nginx.conf <<'NGINXCONF'
worker_processes auto;
events {
    worker_connections 1024;
}

http {
    include       /etc/nginx/mime.types;
    default_type  application/octet-stream;

    sendfile        on;
    keepalive_timeout  65;

    server {
        listen 80;
        server_name _;

        root /var/www/html/public;
        index index.php index.html;

        location / {
            try_files $uri $uri/ /index.php?$query_string;
        }

        location ~ \.php$ {
            include fastcgi_params;
            fastcgi_pass   127.0.0.1:9000;
            fastcgi_index  index.php;
            fastcgi_param  SCRIPT_FILENAME $document_root$fastcgi_script_name;
        }

        location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg)$ {
            try_files $uri $uri/ @rewrite;
            expires max;
            log_not_found off;
        }

        location @rewrite {
            rewrite ^/(.*)$ /index.php?$query_string;
        }
    }
}
NGINXCONF
EOF

# Supervisor configuration to run both php-fpm and nginx
run mkdir -p /etc/supervisor.d

run <<'EOF'
cat >/etc/supervisor.d/supervisord.ini <<'SUPCONF'
[supervisord]
nodaemon=true

[program:php-fpm]
command=docker-php-entrypoint php-fpm
autostart=true
autorestart=true

[program:nginx]
command=/usr/sbin/nginx -g "daemon off;"
autostart=true
autorestart=true
SUPCONF
EOF

expose 80

# Default environment expectations for Coolify:
# - APP_ENV=production
# - APP_KEY is set via Coolify env
# - DB_* variables point to your MySQL service

cmd ["supervisord", "-c", "/etc/supervisor.d/supervisord.ini"]

