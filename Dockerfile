# Render n'a pas de runtime PHP natif (seulement Docker, Node, Python,
# Ruby, Go, Rust, Elixir) - ce Dockerfile fournit Apache + PHP + l'extension
# PostgreSQL (pdo_pgsql, absente de l'image de base) pour executer index.php.
# Apache traite plusieurs visiteurs en meme temps, contrairement a "php -S",
# qui reste reserve au developpement local (voir router.php et Procfile).
FROM php:8.3-apache

# libcurl4-openssl-dev + ext-curl : necessaires pour les appels sortants
# vers l'API Brevo (email) - voir send_email() dans index.php.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libcurl4-openssl-dev \
    && docker-php-ext-install pdo pdo_pgsql curl \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite \
    && echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

# Seule l'API est copiee : le site public est servi par GitHub Pages, et le
# serveur n'a pas a exposer les autres fichiers du projet.
WORKDIR /var/www/html
COPY index.php .htaccess ./

# Render fournit $PORT au demarrage : Apache doit ecouter sur ce port.
CMD ["sh", "-c", "sed -i \"s/^Listen 80$/Listen ${PORT:-80}/\" /etc/apache2/ports.conf && sed -i \"s/<VirtualHost \\*:80>/<VirtualHost *:${PORT:-80}>/\" /etc/apache2/sites-available/000-default.conf && exec apache2-foreground"]
