FROM php:8.2-apache

# Dépendances système + extensions PHP requises par Omeka S
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libicu-dev \
        libxml2-dev \
        unzip \
        wget \
        ca-certificates \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mysqli \
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
RUN wget -q "https://github.com/omeka/omeka-s/releases/download/${OMEKA_S_VERSION}/omeka-s-${OMEKA_S_VERSION}.zip" -O /tmp/omeka-s.zip \
    && unzip -q /tmp/omeka-s.zip -d /tmp \
    && cp -r /tmp/omeka-s/. /var/www/html/ \
    && rm -rf /tmp/omeka-s /tmp/omeka-s.zip \
    && chown -R www-data:www-data /var/www/html

COPY docker/omeka-s/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
