# PHP 8.1 = our production floor. Microsoft devcontainer PHP image:
# includes Composer, git, a non-root 'vscode' user with sudo, and xdebug.
# If this tag ever fails to pull, fallback base is php:8.1-cli-bookworm
# (then Composer, git and the vscode user must be added manually).
FROM mcr.microsoft.com/devcontainers/php:1-8.1-bookworm

# The base image ships a Yarn apt repo with a stale signing key that
# breaks apt-get update. We don't need Yarn; remove the source.
# sqlite3 CLI for inspecting the dev database.
# pdo_sqlite ships enabled with PHP; pdo_mysql added for the optional
# MySQL storage path so both backends are testable in dev.
RUN rm -f /etc/apt/sources.list.d/yarn*.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends sqlite3 \
    && docker-php-ext-install pdo_mysql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*