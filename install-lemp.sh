#!/bin/bash
set -e
export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a
export NEEDRESTART_SUSPEND=1

step() { echo; echo "=== STAGE: $1 ==="; date '+[%H:%M:%S]'; }

step "apt update"
apt-get update -qq

step "apt upgrade"
apt-get -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" upgrade

step "prerequisites"
apt-get install -y software-properties-common ca-certificates lsb-release apt-transport-https gnupg curl

step "Sury PPA (PHP 7.4 для Ubuntu 24.04)"
add-apt-repository -y ppa:ondrej/php
apt-get update -qq

step "web stack: nginx, mariadb, certbot, ufw, fail2ban"
apt-get install -y nginx mariadb-server mariadb-client certbot python3-certbot-nginx ufw fail2ban

step "PHP 7.4 essentials"
apt-get install -y \
    php7.4-fpm php7.4-cli php7.4-common \
    php7.4-mysql php7.4-mbstring php7.4-curl php7.4-xml php7.4-zip \
    php7.4-gd php7.4-bcmath php7.4-intl php7.4-readline php7.4-bz2

step "PHP 7.4 optional (continue on failure)"
for pkg in php7.4-imagick php7.4-redis php7.4-sqlite3 php7.4-igbinary php7.4-soap php7.4-imap; do
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
DELETE FROM mysql.user WHERE User='';
DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost','127.0.0.1','::1');
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\_%';
FLUSH PRIVILEGES;
SQL

step "enable services"
systemctl enable --now nginx mariadb php7.4-fpm

step "versions installed"
nginx -v 2>&1
mariadb --version
php7.4 -v | head -1
echo
echo "Listening sockets:"
ss -tlnp 2>/dev/null | grep -E ':(80|443|3306|9000)\s' | head -10
echo
echo "Active services:"
systemctl is-active nginx mariadb php7.4-fpm

echo
echo "=== DONE ==="
date '+[%H:%M:%S] finished successfully'
