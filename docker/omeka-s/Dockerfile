FROM php:8.2-apache

# Dépendances système + extensions PHP requises par Omeka S
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libicu-dev \
        libxml2-dev \
        libonig-dev \
        libpq-dev \
        unzip \
        wget \
        ca-certificates \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mysqli \
        pdo_pgsql \
        pgsql \
        gd \
        zip \
        exif \
        intl \
        xml \
        mbstring \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

ARG OMEKA_S_VERSION=v4.1.0

WORKDIR /var/www/html

# Récupère la release officielle d'Omeka S depuis GitHub et l'installe dans
# la racine web. Le dossier "modules" du dépôt est monté par-dessus via
# docker-compose (volume), donc le module AiLibrarian n'a pas besoin d'être
# copié dans l'image.
#
# Convention Omeka S : le tag est "vX.Y.Z" mais le nom du zip est
# "omeka-s-X.Y.Z.zip" (sans le "v"). On stripe le "v" pour construire
# correctement le nom de fichier.
RUN VERSION_NUM=$(echo ${OMEKA_S_VERSION} | sed 's/^v//') \
    && wget -q "https://github.com/omeka/omeka-s/releases/download/${OMEKA_S_VERSION}/omeka-s-${VERSION_NUM}.zip" -O /tmp/omeka-s.zip \
    && unzip -q /tmp/omeka-s.zip -d /tmp \
    && cp -r /tmp/omeka-s/. /var/www/html/ \
    && rm -rf /tmp/omeka-s /tmp/omeka-s.zip \
    && chown -R www-data:www-data /var/www/html \
    # Sauvegarde une copie des configs par défaut d'Omeka S dans un dossier
    # séparé. Nécessaire parce que docker-compose monte un volume nommé sur
    # /var/www/html/config, ce qui masque les fichiers de l'image (notamment
    # local.config.php) au premier démarrage. L'entrypoint restaure ces
    # fichiers si le volume config est vide.
    #
    # ATTENTION : on retire database.ini de DEUX endroits :
    #   1. Du backup /opt/omeka-config-default/ pour que l'entrypoint ne
    #      restaure PAS le template vide livré par Omeka S.
    #   2. De l'image /var/www/html/config/ elle-même, parce que Docker
    #      pré-remplit un volume nommé vide avec le contenu du dossier de
    #      l'image au premier montage. Sans cette suppression, le volume
    #      hérite du template vide à l'initialisation, l'entrypoint le voit
    #      exister et refuse de le regénérer. Résultat : host="", user="",
    #      et "Access denied" / "Connection refused" côté PDO.
    #
    # L'entrypoint génère database.ini à chaque démarrage à partir des
    # variables d'environnement (pattern standard des images officielles).
    && cp -r /var/www/html/config /opt/omeka-config-default \
    && rm -f /opt/omeka-config-default/database.ini \
    && rm -f /var/www/html/config/database.ini \
    && chown -R www-data:www-data /opt/omeka-config-default

COPY docker/omeka-s/entrypoint.sh /usr/local/bin/entrypoint.sh
# Convertit les fins de ligne CRLF (Windows) en LF (Unix). Sans ça, le shebang
# "#!/bin/sh\r" est interprété comme un chemin d'interpréteur inexistant et
# Docker échoue avec "exec entrypoint.sh: no such file or directory".
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh

# Config PHP appliquée à chaque démarrage — memory_limit relevé pour la
# recherche sémantique AiLibrarian, log/display errors ON pour éviter les
# 500 silencieux. Sans ce COPY, tout ajustement runtime disparaît au
# --force-recreate du container.
COPY docker/omeka-s/php-errors.ini /usr/local/etc/php/conf.d/zz-omeka.ini

EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
