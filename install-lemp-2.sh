#!/bin/bash
set -e
export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a
export NEEDRESTART_SUSPEND=1

step() { echo; echo "=== STAGE: $1 ==="; date '+[%H:%M:%S]'; }

step "PHP 8.3 essentials (Ubuntu native)"
apt-get install -y \
    php8.3-fpm php8.3-cli php8.3-common \
    php8.3-mysql php8.3-mbstring php8.3-curl php8.3-xml php8.3-zip \
    php8.3-gd php8.3-bcmath php8.3-intl php8.3-readline php8.3-bz2 \
    imagemagick

step "PHP 8.3 optional (continue on failure)"
for pkg in php8.3-imagick php8.3-redis php8.3-sqlite3 php8.3-igbinary php8.3-soap php8.3-imap; do
    if apt-get install -y "$pkg" 2>/dev/null; then
        echo "  installed: $pkg"
    else
        echo "  WARN: $pkg not available, skipped"
    fi
done

step "ufw firewall"
ufw --force reset >/dev/null
ufw default deny incoming
ufw default allow outgoing
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable
ufw status verbose

step "secure mariadb (idempotent)"
mysql -u root <<'SQL'
DELETE FROM mysql.global_priv WHERE User='';
DELETE FROM mysql.global_priv WHERE User='root' AND Host NOT IN ('localhost','127.0.0.1','::1');
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\_%';
FLUSH PRIVILEGES;
SQL

step "enable services"
systemctl enable --now nginx mariadb php8.3-fpm

step "summary"
echo "--- nginx ---"
nginx -v 2>&1
echo "--- mariadb ---"
mariadb --version
echo "--- php ---"
php -v | head -1
echo
echo "--- PHP modules ---"
php -m | sort | column
echo
echo "--- Listening sockets ---"
ss -tlnp 2>/dev/null | grep -E ':(80|443|3306|9000|9001)\s' | head -10
echo
echo "--- Active services ---"
systemctl is-active nginx mariadb php8.3-fpm

echo
echo "=== DONE ==="
date '+[%H:%M:%S] continuation finished'
