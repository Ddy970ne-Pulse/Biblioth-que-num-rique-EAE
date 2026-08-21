#!/bin/sh
set -e

CONFIG_DIR=/var/www/html/config
DB_INI="$CONFIG_DIR/database.ini"
CONFIG_DEFAULT=/opt/omeka-config-default

# Le volume nommé "omeka_config" monté sur $CONFIG_DIR est vide au premier
# démarrage. Docker le pré-remplit avec le contenu de l'image, mais notre
# Dockerfile a retiré database.ini justement pour que l'entrypoint le
# regénère avec les variables d'environnement (voir Dockerfile pour le
# détail du raisonnement).
#
# On détecte un volume config vraiment vide via l'absence de local.config.php
# (fichier livré par Omeka S dans config/, contrairement à
# application.config.php qui vit dans application/config/). Si absent, on
# restaure les configs par défaut depuis /opt/omeka-config-default (préservé
# par le Dockerfile avant que le volume ne masque le dossier).
mkdir -p "$CONFIG_DIR"

if [ ! -f "$CONFIG_DIR/local.config.php" ]; then
    echo "Volume config vide détecté, restauration des configs par défaut depuis $CONFIG_DEFAULT"
    cp -rn "$CONFIG_DEFAULT/." "$CONFIG_DIR/"
    chown -R www-data:www-data "$CONFIG_DIR"
fi

# Attend que MySQL accepte les connexions avant de démarrer Apache.
echo "En attente de la base de données MySQL ($MYSQL_HOST:$MYSQL_PORT)..."
until php -r "new PDO('mysql:host=$MYSQL_HOST;port=$MYSQL_PORT', '$MYSQL_USER', '$MYSQL_PASSWORD');" 2>/dev/null; do
    sleep 2
done
echo "Base de données disponible."

# Régénère config/database.ini à chaque démarrage à partir des variables
# d'environnement. C'est le pattern standard des images officielles
# (wordpress, nextcloud, etc.) : la source de vérité est docker-compose /
# .env, pas un fichier persisté dans le volume. Éviter le test d'existence
# évite le bug classique du template vide qui reste vide.
#
# ATTENTION AU FORMAT : Omeka S attend un fichier INI SANS section (clés à
# la racine, parsées avec parse_ini_file() sans le flag INI_SCANNER_NORMAL).
# Ne PAS ajouter [database] en tête, sinon les clés se retrouvent dans une
# sous-section qu'Omeka ne lit pas → host vide → PDO retombe sur socket
# Unix locale → SQLSTATE[HY000] [2002] No such file or directory.
echo "Génération de $DB_INI"
cat > "$DB_INI" <<EOF
user     = "${MYSQL_USER}"
password = "${MYSQL_PASSWORD}"
dbname   = "${MYSQL_DATABASE}"
host     = "${MYSQL_HOST}"
port     = "${MYSQL_PORT}"
EOF
chown www-data:www-data "$DB_INI"

# S'assure que les dossiers nécessitant l'écriture par Omeka S sont bien
# possédés par www-data (utile après montage d'un volume).
mkdir -p /var/www/html/files /var/www/html/logs
chown -R www-data:www-data /var/www/html/files /var/www/html/logs /var/www/html/config

exec "$@"
