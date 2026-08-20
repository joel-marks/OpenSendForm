# PHP 8.1 = our production floor. Microsoft devcontainer PHP image:
# includes Composer, git, a non-root 'vscode' user with sudo, and xdebug.
# If this tag ever fails to pull, fallback base is php:8.1-cli-bookworm
# (then Composer, git and the vscode user must be added manually).
FROM mcr.microsoft.com/devcontainers/php:1-8.1-bookworm

# sqlite3 CLI for inspecting the dev database.
# pdo_sqlite ships enabled with PHP; pdo_mysql added for the optional
# MySQL storage path so both backends are testable in dev.
RUN apt-get update \
    && apt-get install -y --no-install-recommends sqlite3 \
    && docker-php-ext-install pdo_mysql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*