# Collector-only image. No web server, no ports, no ACS management handlers.
FROM php:8.3-cli-bookworm
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates libcurl4-openssl-dev \
    && docker-php-ext-install mysqli curl \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY collector /app/collector
COPY lib/GenieACS_Fast.php /app/lib/GenieACS_Fast.php
COPY lib/JrcSnmpTelemetry.php /app/lib/JrcSnmpTelemetry.php
USER 10001:10001
ENTRYPOINT ["php", "/app/collector/cli.php"]
CMD ["--check"]
