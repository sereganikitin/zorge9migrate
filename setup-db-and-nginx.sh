#!/bin/bash
set -e
export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a
export NEEDRESTART_SUSPEND=1

step() { echo; echo "=== STAGE: $1 ==="; date '+[%H:%M:%S]'; }

# DB credentials передаются через env vars (читаются из .env-db рядом с
# run-setup.ps1, см. .env-db.example). Берутся из htdocs/config.php
# мигрируемого сайта — те же параметры используем здесь, чтобы не править
# конфиг сайта при переезде.
DB_USER="${DB_USER:?DB_USER не задан — см. .env-db.example}"
DB_PASS="${DB_PASS:?DB_PASS не задан — см. .env-db.example}"

step "redis-server"
apt-get install -y redis-server
systemctl enable --now redis-server
redis-cli ping || true

step "create databases + user"
mysql <<EOF
CREATE DATABASE IF NOT EXISTS zorge9 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS feedbacks DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON zorge9.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON feedbacks.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
EOF
mysql -e "SHOW DATABASES;"

step "import zorge9"
gunzip < /tmp/zorge9_import/zorge9.sql.gz | mysql zorge9
mysql zorge9 -e "SHOW TABLES;" | wc -l
echo "tables in zorge9 ↑"

step "import feedbacks"
gunzip < /tmp/zorge9_import/feedbacks.sql.gz | mysql feedbacks
mysql feedbacks -e "SHOW TABLES;" | wc -l
echo "tables in feedbacks ↑"

step "PHP-FPM pool tune (как было на старом)"
PFPM=/etc/php/8.3/fpm/pool.d/www.conf
sed -i -E 's/^pm\.max_children = .*/pm.max_children = 60/' "$PFPM"
sed -i -E 's/^pm\.start_servers = .*/pm.start_servers = 16/' "$PFPM"
sed -i -E 's/^pm\.min_spare_servers = .*/pm.min_spare_servers = 8/' "$PFPM"
sed -i -E 's/^pm\.max_spare_servers = .*/pm.max_spare_servers = 24/' "$PFPM"
sed -i -E 's/^;?pm\.max_requests = .*/pm.max_requests = 600/' "$PFPM"
grep -E '^pm\.|^listen ' "$PFPM"

step "php.ini tune"
PINI=/etc/php/8.3/fpm/php.ini
sed -i -E 's/^memory_limit = .*/memory_limit = 256M/' "$PINI"
sed -i -E 's/^upload_max_filesize = .*/upload_max_filesize = 64M/' "$PINI"
sed -i -E 's/^post_max_size = .*/post_max_size = 64M/' "$PINI"
sed -i -E 's|^;?date\.timezone =.*|date.timezone = Europe/Moscow|' "$PINI"

step "ownership /var/www/old.zorge9.com"
chown -R www-data:www-data /var/www/old.zorge9.com
chmod -R u=rwX,g=rX,o=rX /var/www/old.zorge9.com
# дать запись для писменной работы CMS / форм / кэша
chmod -R g+w /var/www/old.zorge9.com/htdocs/wolf/plugins/feedbacks/data 2>/dev/null || true
chmod -R g+w /var/www/old.zorge9.com/htdocs/wolf/plugins/feedbacks/attachments 2>/dev/null || true
chmod -R g+w /var/www/old.zorge9.com/htdocs/wolf/cache 2>/dev/null || true
chmod -R g+w /var/www/old.zorge9.com/htdocs/hydra/pdf/cache 2>/dev/null || true

step "restart php-fpm"
systemctl restart php8.3-fpm
systemctl is-active php8.3-fpm

step "nginx config (test mode: HTTP only, default_server)"
rm -f /etc/nginx/sites-enabled/default
cat > /etc/nginx/sites-available/old.zorge9.com <<'NGINX_EOF'
upstream php-handler_old.zorge9 {
    server unix:/var/run/php/php8.3-fpm.sock;
}

server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;

    # index.html идёт первым: после интеграции лендинга в `/` лежит статика
    # (site/index.html), а Wolf CMS index.php обслуживает остальные пути через @wolf.
    index index.html index.php;
    charset utf-8;
    autoindex off;
    root /var/www/old.zorge9.com/htdocs;
    access_log /var/log/nginx/old.zorge9.com-access.log;
    error_log /var/log/nginx/old.zorge9.com-error.log;

    gzip on;
    gzip_vary on;
    gzip_comp_level 4;
    gzip_min_length 256;
    gzip_types application/javascript application/json application/xml text/css text/plain text/xml image/svg+xml application/atom+xml application/ld+json font/opentype;

    fastcgi_hide_header X-Powered-By;
    client_max_body_size 64M;

    location ~ \.(php|phtml)$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param QUERY_STRING $query_string;
        fastcgi_param REQUEST_METHOD $request_method;
        fastcgi_param CONTENT_TYPE $content_type;
        fastcgi_param CONTENT_LENGTH $content_length;
        fastcgi_pass php-handler_old.zorge9;
    }

    location ~* ^.+\.(?:css|cur|js|jpg|jpeg|gif|htc|ico|png|html|otf|ttf|eot|woff|woff2|svg|mp4)$ {
        access_log off;
        expires 30d;
        add_header Cache-Control private;
    }

    # ───────── Symfony CMS admin (EasyAdmin) at /cms-admin/ ─────────
    # Mounted via symlink:
    #   ln -sfn /var/www/cms-admin/public /var/www/old.zorge9.com/htdocs/cms-admin
    # Symfony's front controller lives at /var/www/cms-admin/public/index.php.
    location ~ ^/cms-admin/index\.php(/|$) {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $document_root;
        fastcgi_param REQUEST_METHOD $request_method;
        fastcgi_param QUERY_STRING $query_string;
        fastcgi_param CONTENT_TYPE $content_type;
        fastcgi_param CONTENT_LENGTH $content_length;
        fastcgi_pass php-handler_old.zorge9;
    }
    location /cms-admin/ {
        try_files $uri $uri/ /cms-admin/index.php$is_args$args;
    }

    # ───────── Landing pages routed through the CMS render middleware ─────────
    # _cms-render.php reads the static HTML, applies TextBlock/ImageBlock
    # overrides from the cms_admin DB, and streams the result. Wolf-served
    # pages (everything not listed here) keep going through location / below.
    location = / {
        rewrite ^ /_cms-render.php?page= last;
    }
    location ~ ^/(apartments|improvement|infrastructure|investment|location|management|parking|penthouses|privacy-policy|request|services|style)(?:/(?:index\.html)?)?$ {
        rewrite ^/([^/]+).*$ /_cms-render.php?page=$1 last;
    }

    location / {
        try_files $uri $uri/ @wolf;
    }

    # Wolf CMS dispatcher: WOLFPAGE без ведущего слэша (как было в .htaccess).
    location @wolf {
        rewrite ^/(.*)$ /index.php?WOLFPAGE=$1&$args last;
    }

    location /index {
        rewrite ^/index\.php http://$http_host/ permanent;
    }

    location = /favicon.ico { log_not_found off; access_log off; }
    location ~ /\.ht { deny all; }
    location ~ .*\.(htaccess|htpasswd|ini|fla|psd|log|sh|sqlite|sq3|git|gitignore|svn)$ { deny all; }

    # PDF-rewrite только для GET (POST не трогаем — иначе теряется тело формы).
    if ($request_method = GET) {
        set $maybe_rewrite "1";
    }
    if (!-e $request_filename) {
        set $maybe_rewrite "${maybe_rewrite}1";
    }
    if ($maybe_rewrite = "11") {
        rewrite ^/pdf/([^\/]*)\.pdf$ /hydra/pdf/cache/booklets/$1.pdf break;
        rewrite ^/pdf/download/([^\/]*).pdf$ /hydra/pdf/downloader.php?filename=$1.pdf break;
        # trailing-slash strip убран: 301 при POST превращает запрос в GET и теряет тело.
    }
}
NGINX_EOF
ln -sf /etc/nginx/sites-available/old.zorge9.com /etc/nginx/sites-enabled/old.zorge9.com

step "nginx -t + reload"
nginx -t
systemctl reload nginx

step "smoke test (curl localhost)"
curl -sS -o /tmp/curl-home.html -w "HTTP %{http_code} size=%{size_download} time=%{time_total}s\n" http://127.0.0.1/ || true
echo "--- первые 300 байт ответа ---"
head -c 300 /tmp/curl-home.html
echo
echo "--- recent error log ---"
tail -20 /var/log/nginx/old.zorge9.com-error.log 2>/dev/null
tail -20 /var/log/php8.3-fpm.log 2>/dev/null

echo
echo "=== DONE ==="
date '+[%H:%M:%S]'
