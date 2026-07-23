#!/bin/sh
set -e

# Attend que MySQL accepte les connexions avant de démarrer Apache.
echo "En attente de la base de données MySQL ($MYSQL_HOST:$MYSQL_PORT)..."
until php -r "new PDO('mysql:host=$MYSQL_HOST;port=$MYSQL_PORT', '$MYSQL_USER', '$MYSQL_PASSWORD');" 2>/dev/null; do
    sleep 2
done
echo "Base de données disponible."

# Génère config/database.ini au premier démarrage si absent. Sur les
# démarrages suivants, le fichier existant (persisté via le volume
# omeka_config) n'est pas écrasé.
CONFIG_DIR=/var/www/html/config
DB_INI="$CONFIG_DIR/database.ini"

mkdir -p "$CONFIG_DIR"

if [ ! -f "$DB_INI" ]; then
    echo "Génération de $DB_INI"
    cat > "$DB_INI" <<EOF
[database]
host     = "${MYSQL_HOST}"
dbname   = "${MYSQL_DATABASE}"
user     = "${MYSQL_USER}"
password = "${MYSQL_PASSWORD}"
port     = ${MYSQL_PORT}
EOF
fi

# S'assure que les dossiers nécessitant l'écriture par Omeka S sont bien
# possédés par www-data (utile après montage d'un volume).
mkdir -p /var/www/html/files /var/www/html/logs
chown -R www-data:www-data /var/www/html/files /var/www/html/logs /var/www/html/config

exec "$@"
