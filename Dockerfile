FROM php:8.3-apache

# ── System dependencies ───────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
        # FTP client for deploying to remote host
        lftp \
        # SQLite dev headers (needed for PHP pdo_sqlite extension)
        libsqlite3-dev \
        # Git — optional, useful if you want version control from inside
        git \
        # zip/unzip for composer
        unzip \
        zip \
    && rm -rf /var/lib/apt/lists/*

# ── PHP extensions ────────────────────────────────────────────
# pdo_sqlite: database access in build.php
# opcache:    speeds up repeated PHP CLI script execution
RUN docker-php-ext-install pdo pdo_sqlite opcache

# ── Composer ─────────────────────────────────────────────────
# Install composer globally so we can pull in Parsedown
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── Parsedown (Markdown renderer) ────────────────────────────
# Install into a fixed path that build.php references directly.
# Parsedown is pure-PHP, no native extensions needed.
RUN mkdir -p /usr/local/lib/parsedown && \
    composer require erusev/parsedown \
        --working-dir=/usr/local/lib/parsedown \
        --no-interaction \
        --prefer-dist \
        --no-progress && \
    # Flatten: build.php expects Parsedown.php directly in the dir
    cp /usr/local/lib/parsedown/vendor/erusev/parsedown/Parsedown.php \
       /usr/local/lib/parsedown/Parsedown.php

# ── Apache configuration ──────────────────────────────────────
# Enable mod_rewrite in case you add pretty URLs later
RUN a2enmod rewrite

# Relax directory permissions for AllowOverride (allows .htaccess)
RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

# ── SQLite database directory ─────────────────────────────────
# This directory is created inside the container. If you want
# the SQLite DB to persist between container restarts, mount it
# as a volume in docker-compose (see compose file).
RUN mkdir -p /var/lib/sitedb && chmod 755 /var/lib/sitedb

# ── Copy scripts into container ───────────────────────────────
# These are the build and publish scripts. They are baked into
# the image — update the image to update the scripts.
COPY scripts/ /scripts/
RUN chmod +x /scripts/build.php /scripts/publish.php

# ── Document root ─────────────────────────────────────────────
# Apache serves from here. This directory will be OVERRIDDEN
# by the NFS volume mount at runtime, so files placed here in
# the image are only fallback placeholders.
#
# The actual html/ directory is:
#   /mnt/hostess/media/projects/webdev/justinjanson.com/html
# mounted to /var/www/html via docker-compose.

# Default document root stays /var/www/html (Apache default)

# ── Permissions ───────────────────────────────────────────────
# Ensure www-data can write generated HTML files
# (build.php writes blog.html, projects.html, blog/*.html)
# This matters if your NFS volume has tight permissions.
# Adjust UID/GID to match your NFS export if needed.

EXPOSE 80

# Apache stays in the foreground (default for this base image)
CMD ["apache2-foreground"]
