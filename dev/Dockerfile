# Providence de développement pour le connecteur Meilisearch.
#
# Dérivé du harnais du greffon lescollections (dev/Dockerfile), même fork, même ref épinglée,
# mêmes extensions PHP. Ce qui change : pas de greffon lescollections monté, `curl` disponible
# pour interroger Meilisearch depuis le conteneur, et les outils du harnais adaptés.

FROM php:8.4-apache

# Le code de Providence : fork public de lescollections, épinglé au tag de prod.
ARG PROVIDENCE_REPO=https://github.com/lescollections/providence.git
ARG PROVIDENCE_REF=lescollections_260803_0909
ARG PROVIDENCE_SHA=667582bf6ac507f3d651d2188c5d38d60de0bdf8

ENV COLLECTIVEACCESS_HOME=/var/www/html \
    DEBIAN_FRONTEND=noninteractive

# ── Extensions PHP ────────────────────────────────────────────────────────────────────────
# gd (jpeg/webp/freetype), mysqli, zip, exif, bcmath, intl. Composer refuse de résoudre les
# dépendances de CollectiveAccess sans gd, zip et intl notamment.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        curl \
        unzip \
        ca-certificates \
        default-mysql-client \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        libicu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd mysqli zip exif bcmath intl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# ── Le code de Providence ─────────────────────────────────────────────────────────────────
# On vérifie le SHA : le tag doit pointer exactement sur l'octet attendu, sinon on casse le
# build plutôt que de développer contre autre chose.
WORKDIR /var/www
RUN rm -rf /var/www/html \
    && git clone --depth 1 --branch "${PROVIDENCE_REF}" "${PROVIDENCE_REPO}" /var/www/html \
    && test "$(git -C /var/www/html rev-parse HEAD)" = "${PROVIDENCE_SHA}" \
    && rm -rf /var/www/html/.git

WORKDIR /var/www/html

# CollectiveAccess épingle `dompdf ~2.0.2`, que Composer 2.9 refuse d'installer parce que
# toutes les 2.0.x portent des avis de sécurité (rendu PDF). La contrainte vient du fork ; la
# lever reviendrait à ne plus être aligné. Acceptable pour un harnais qui n'écoute que sur
# localhost et ne rend aucun PDF depuis une source non maîtrisée.
RUN composer config --global policy.advisories.block false \
    && composer install --no-dev --no-interaction --prefer-dist --no-progress \
    && composer clear-cache

# ── Apache ────────────────────────────────────────────────────────────────────────────────
# L'app est servie sous /gestion. `+FollowSymLinks` (dans le vhost) est indispensable : le
# greffon est installé par liens symboliques.
COPY conf/apache-gestion.conf /etc/apache2/sites-available/meilisearch-gestion.conf
RUN a2enmod rewrite headers \
    && a2dissite 000-default \
    && a2ensite meilisearch-gestion \
    && mkdir -p /var/www/site

# ── PHP et setup.php ──────────────────────────────────────────────────────────────────────
COPY conf/php-dev.ini /usr/local/etc/php/conf.d/zz-meilisearch-dev.ini
COPY conf/setup.php /var/www/html/setup.php
COPY conf/local-app.conf /opt/meilisearch/local-app.conf.tmpl

# ── Outils du harnais ─────────────────────────────────────────────────────────────────────
COPY bin/entrypoint.sh /usr/local/bin/dev-entrypoint
COPY bin/dev-install /usr/local/bin/dev-install
COPY bin/dev-link-plugin /usr/local/bin/dev-link-plugin
COPY bin/dev-seed-cleveland /usr/local/bin/dev-seed-cleveland
COPY bin/dev-seed-cma /usr/local/bin/dev-seed-cma
COPY bin/dev-seed-rijksmuseum /usr/local/bin/dev-seed-rijksmuseum
COPY bin/dev-test /usr/local/bin/dev-test
COPY bin/dev-reindex /usr/local/bin/dev-reindex
COPY bin/dev-elaguer /usr/local/bin/dev-elaguer
COPY bin/seed-cleveland.php /opt/meilisearch/seed-cleveland.php
COPY bin/seed-cma.php /opt/meilisearch/seed-cma.php
COPY bin/seed-rijksmuseum.php /opt/meilisearch/seed-rijksmuseum.php
RUN chmod +x /usr/local/bin/dev-entrypoint \
             /usr/local/bin/dev-install \
             /usr/local/bin/dev-link-plugin \
             /usr/local/bin/dev-seed-cleveland \
             /usr/local/bin/dev-seed-cma \
             /usr/local/bin/dev-seed-rijksmuseum \
             /usr/local/bin/dev-test \
             /usr/local/bin/dev-reindex \
             /usr/local/bin/dev-elaguer \
    && ln -s /var/www/html/support/bin/caUtils /usr/local/bin/caUtils

# Les dossiers dans lesquels CollectiveAccess écrit. `media` et `uploads` reçoivent un volume
# nommé au montage ; on les crée et on les donne à www-data ici pour que le volume hérite des
# bons droits à la première création.
RUN mkdir -p app/tmp app/log app/fonts app/conf/local media uploads \
    && chown -R www-data:www-data /var/www/html

EXPOSE 80
ENTRYPOINT ["dev-entrypoint"]
CMD ["apache2-foreground"]
