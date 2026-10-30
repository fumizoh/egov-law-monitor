FROM php:8.3-cli-bookworm

ARG WORDPRESS_VERSION=6.8.3

ENV WP_TESTS_DIR=/opt/wordpress-tests \
    WP_CORE_DIR=/opt/wordpress \
    WP_TEST_TOOLS_DIR=/opt/wp-test-tools

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        default-mysql-client \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libxslt1-dev \
        libzip-dev \
        subversion \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd intl mysqli pdo_mysql xsl zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN mkdir -p "${WP_TEST_TOOLS_DIR}/phpunit-polyfills" \
    && curl --fail --silent --show-error --location \
        https://phar.phpunit.de/phpunit-9.6.29.phar \
        --output "${WP_TEST_TOOLS_DIR}/phpunit-9.6.29.phar" \
    && chmod 0755 "${WP_TEST_TOOLS_DIR}/phpunit-9.6.29.phar" \
    && php "${WP_TEST_TOOLS_DIR}/phpunit-9.6.29.phar" --version \
    && curl --fail --silent --show-error --location \
        https://github.com/Yoast/PHPUnit-Polyfills/archive/refs/tags/1.1.4.zip \
        --output /tmp/phpunit-polyfills.zip \
    && unzip -q /tmp/phpunit-polyfills.zip -d /tmp \
    && cp -R /tmp/PHPUnit-Polyfills-1.1.4/. "${WP_TEST_TOOLS_DIR}/phpunit-polyfills/" \
    && rm -f /tmp/phpunit-polyfills.zip \
    && mkdir -p "${WP_CORE_DIR}" \
    && curl --fail --silent --show-error --location \
        "https://wordpress.org/wordpress-${WORDPRESS_VERSION}.tar.gz" \
        | tar -xz --strip-components=1 -C "${WP_CORE_DIR}" \
    && svn export --quiet \
        "https://develop.svn.wordpress.org/tags/${WORDPRESS_VERSION}/tests/phpunit" \
        "${WP_TESTS_DIR}"

WORKDIR /workspace

CMD ["php", "/opt/wp-test-tools/phpunit-9.6.29.phar", "--configuration", "phpunit.integration.xml.dist"]
