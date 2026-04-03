#!/usr/bin/env bash
# ============================================================
# Pantone Predictor — Automated Installer for Ubuntu 22.04+
# Run: sudo bash install.sh
# ============================================================
set -euo pipefail

APP_DIR="/var/www/pantone-predictor"
DB_NAME="pantone_predictor"
DB_USER="pantone_user"
REPO_URL="https://github.com/mattcomputers-ctrl/PMS-Predictor.git"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
log()  { echo -e "${GREEN}[+]${NC} $1"; }
warn() { echo -e "${YELLOW}[!]${NC} $1"; }
err()  { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }

[[ $EUID -ne 0 ]] && err "Run as root: sudo bash install.sh"

echo ""
echo -e "${CYAN}================================================${NC}"
echo -e "${CYAN}  Pantone Predictor — Installer${NC}"
echo -e "${CYAN}================================================${NC}"
echo ""

# ── Generate credentials ──────────────────────────────────
DB_PASS=$(openssl rand -base64 32 | tr -dc 'a-zA-Z0-9' | head -c 24)
ADMIN_PASS=$(openssl rand -base64 16 | tr -dc 'a-zA-Z0-9' | head -c 16)
ADMIN_USER="admin"
SERVER_IP=$(hostname -I | awk '{print $1}')

log "Server IP: ${SERVER_IP}"

# ── System packages ───────────────────────────────────────
export DEBIAN_FRONTEND=noninteractive

log "Updating system packages..."
apt-get update -qq

log "Installing Nginx, MySQL, and utilities..."
apt-get install -y -qq nginx mysql-server curl unzip git \
    apt-transport-https gnupg2 software-properties-common > /dev/null 2>&1

# ── PHP ───────────────────────────────────────────────────
if ! apt-cache show php8.2-fpm &>/dev/null 2>&1; then
    log "Adding PHP PPA..."
    add-apt-repository -y ppa:ondrej/php > /dev/null 2>&1
    apt-get update -qq
fi

# Find best PHP version
PHP_VER="8.2"
for v in 8.3 8.2 8.1; do
    if apt-cache show "php${v}-fpm" &>/dev/null 2>&1; then
        PHP_VER="$v"; break
    fi
done

log "Installing PHP ${PHP_VER}..."
apt-get install -y -qq \
    "php${PHP_VER}-fpm" "php${PHP_VER}-cli" "php${PHP_VER}-mysql" \
    "php${PHP_VER}-mbstring" "php${PHP_VER}-xml" "php${PHP_VER}-curl" \
    "php${PHP_VER}-zip" "php${PHP_VER}-dev" unixodbc-dev > /dev/null 2>&1

PHP_FPM_SOCK="/run/php/php${PHP_VER}-fpm.sock"

# ── Microsoft ODBC Driver ─────────────────────────────────
if ! dpkg -l msodbcsql18 &>/dev/null 2>&1; then
    log "Installing Microsoft ODBC Driver 18..."
    curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | \
        gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg 2>/dev/null

    UBUNTU_CODENAME=$(lsb_release -cs 2>/dev/null || echo "jammy")
    UBUNTU_VER=$(lsb_release -rs 2>/dev/null || echo "22.04")
    echo "deb [arch=amd64,arm64 signed-by=/usr/share/keyrings/microsoft-prod.gpg] https://packages.microsoft.com/ubuntu/${UBUNTU_VER}/prod ${UBUNTU_CODENAME} main" \
        > /etc/apt/sources.list.d/mssql-release.list 2>/dev/null || \
    echo "deb [arch=amd64 signed-by=/usr/share/keyrings/microsoft-prod.gpg] https://packages.microsoft.com/ubuntu/22.04/prod jammy main" \
        > /etc/apt/sources.list.d/mssql-release.list

    apt-get update -qq
    ACCEPT_EULA=Y apt-get install -y -qq msodbcsql18 > /dev/null 2>&1 || warn "ODBC driver install had warnings"
fi

# ── PHP SQL Server extensions ─────────────────────────────
if ! php -m 2>/dev/null | grep -q pdo_sqlsrv; then
    log "Installing PHP SQL Server extensions..."
    pecl channel-update pecl.php.net > /dev/null 2>&1 || true
    printf "\n" | pecl install sqlsrv > /dev/null 2>&1 || true
    printf "\n" | pecl install pdo_sqlsrv > /dev/null 2>&1 || true
    echo "extension=sqlsrv.so" > "/etc/php/${PHP_VER}/mods-available/sqlsrv.ini"
    echo "extension=pdo_sqlsrv.so" > "/etc/php/${PHP_VER}/mods-available/pdo_sqlsrv.ini"
    phpenmod -v "${PHP_VER}" sqlsrv pdo_sqlsrv 2>/dev/null || true
fi

# ── Composer ──────────────────────────────────────────────
if ! command -v composer &>/dev/null; then
    log "Installing Composer..."
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer > /dev/null 2>&1
fi

# ── MySQL Database ────────────────────────────────────────
log "Setting up MySQL database..."
systemctl start mysql 2>/dev/null || true
mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null
mysql -e "DROP USER IF EXISTS '${DB_USER}'@'localhost';" 2>/dev/null || true
mysql -e "CREATE USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';" 2>/dev/null
mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';" 2>/dev/null
mysql -e "FLUSH PRIVILEGES;" 2>/dev/null

# ── Deploy Application ────────────────────────────────────
log "Deploying application..."
if [ -d "${APP_DIR}/.git" ]; then
    git -C "${APP_DIR}" fetch --all -q
    git -C "${APP_DIR}" reset --hard origin/main -q
else
    rm -rf "${APP_DIR}"
    git clone -q "${REPO_URL}" "${APP_DIR}"
fi

# ── Import Schema ─────────────────────────────────────────
log "Importing database schema..."
mysql -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" < "${APP_DIR}/database/schema.sql" 2>/dev/null

# ── Create Admin User ─────────────────────────────────────
log "Creating admin user..."
ADMIN_HASH=$(php -r "echo password_hash('${ADMIN_PASS}', PASSWORD_BCRYPT);")
mysql -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" -e \
    "INSERT INTO users (username, password_hash, display_name, is_admin)
     VALUES ('${ADMIN_USER}', '${ADMIN_HASH}', 'Administrator', 1)
     ON DUPLICATE KEY UPDATE password_hash = '${ADMIN_HASH}';" 2>/dev/null

# ── Generate Config ───────────────────────────────────────
log "Generating configuration..."
cat > "${APP_DIR}/config/config.php" <<PHPEOF
<?php
return [
    'app' => [
        'name'     => 'Pantone Predictor',
        'debug'    => false,
        'timezone' => 'America/New_York',
        'url'      => 'http://${SERVER_IP}',
    ],
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => '${DB_NAME}',
        'user'     => '${DB_USER}',
        'password' => '${DB_PASS}',
        'charset'  => 'utf8mb4',
    ],
    'session' => [
        'name'     => 'pantone_session',
        'lifetime' => 7200,
    ],
    'paths' => [
        'data' => __DIR__ . '/../data',
    ],
];
PHPEOF

# ── Composer Autoload ─────────────────────────────────────
log "Setting up autoloader..."
cd "${APP_DIR}"
composer dump-autoload --quiet --no-interaction 2>/dev/null || true

# ── Permissions ───────────────────────────────────────────
log "Setting permissions..."
chown -R www-data:www-data "${APP_DIR}"
chmod -R 755 "${APP_DIR}"
chmod 640 "${APP_DIR}/config/config.php"
chown www-data:www-data "${APP_DIR}/config/config.php"

# ── Nginx ─────────────────────────────────────────────────
log "Configuring Nginx..."
sed "s|unix:/run/php/php-fpm.sock|unix:${PHP_FPM_SOCK}|g" \
    "${APP_DIR}/nginx/pantone-predictor.conf" > /etc/nginx/sites-available/pantone-predictor
ln -sf /etc/nginx/sites-available/pantone-predictor /etc/nginx/sites-enabled/pantone-predictor
rm -f /etc/nginx/sites-enabled/default
nginx -t 2>/dev/null && systemctl reload nginx

# ── Restart Services ──────────────────────────────────────
log "Restarting services..."
systemctl restart "php${PHP_VER}-fpm"
systemctl restart nginx
systemctl enable nginx mysql "php${PHP_VER}-fpm" 2>/dev/null

echo ""
echo -e "${GREEN}================================================${NC}"
echo -e "${GREEN}  Pantone Predictor installed successfully!${NC}"
echo -e "${GREEN}================================================${NC}"
echo ""
echo -e "  URL:      ${CYAN}http://${SERVER_IP}${NC}"
echo -e "  Username: ${CYAN}${ADMIN_USER}${NC}"
echo -e "  Password: ${CYAN}${ADMIN_PASS}${NC}"
echo ""
echo -e "  ${YELLOW}Log in and configure the CMS database${NC}"
echo -e "  ${YELLOW}connection under Settings.${NC}"
echo ""
echo -e "${GREEN}================================================${NC}"
echo ""
