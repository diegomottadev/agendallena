FROM php:8.4-cli

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# pdo_mysql: MySQL. redis: cliente phpredis (colas, cache y locks de estado).
# bcmath e intl: montos de cotizacion y formato por locale. zip: composer.
#
# ---------------------------------------------------------------------------
# tzdata: por que la version de esta imagen es parte del contrato (T-031)
# ---------------------------------------------------------------------------
#
# Los husos horarios de la region cambian por decreto y sin aviso: Chile corrio
# las fechas de su cambio estacional varias veces, Mexico elimino el horario de
# verano en 2022 y Paraguay lo elimino en 2024. Una imagen construida con una
# base de husos vieja sigue aplicando la regla derogada.
#
# El sintoma es el peor que tiene el producto: **el turno sale corrido una hora
# y no hay ningun error**. Ni excepcion, ni log, ni 500. El cliente lee 15:00 en
# el chat, el duenio ve 16:00 en su calendario, y nadie se entera hasta que
# alguien llega tarde. Solo pasa unas semanas al ano, asi que tampoco se
# reproduce cuando se lo busca.
#
# En este contenedor conviven DOS bases de husos, y no son la misma:
#
#   php -r 'echo timezone_version_get();'        -> 2026.3  (timelib, la de PHP)
#   php -r 'echo intltz_get_tz_data_version();'  -> 2024b   (la que trae ICU)
#
# La de PHP es la que usan DateTime, Carbon y por lo tanto todo el producto via
# App\Support\HoraLocal. La de ICU viaja adentro de la extension intl y **esta
# atrasada**: con America/Asuncion aplica el horario de verano que Paraguay
# derogo y devuelve UTC-4 siete meses al ano, cuando el pais esta fijo en UTC-3.
#
# No se fija una version exacta con apt: adelantarse siempre es correcto y
# clavar un numero solo consigue que el build se rompa cuando el paquete rota
# del mirror. El piso lo pone la suite, que corre en CI y falla el build si la
# imagen retrocede de version o si alguna conversion empieza a resolverse con la
# base de ICU:
#
#   docker compose exec -T app php artisan test --filter=ZonasHorariasTransversalTest
#
# Al subir la imagen base, correr esa suite y actualizar TZDATA_PHP_MINIMA /
# TZDATA_ICU_MINIMA en tests/Feature/ZonasHorariasTransversalTest.php.
RUN install-php-extensions pdo_mysql redis bcmath intl zip opcache \
    && apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

# `artisan serve` corre bajo el SAPI de CLI, donde opcache viene apagado por
# defecto. Encenderlo cachea el bytecode entre requests.
#
# OJO con lo que esto NO arregla. Con opcache activo el request igual tarda
# ~3.100 ms, porque `validate_timestamps=1` hace un stat() de los ~573 archivos
# cacheados en cada request, y sobre el bind mount de Docker Desktop en Windows
# cada stat cuesta milisegundos (leer 300 archivos del bind mount: 9.652 ms;
# los mismos desde el disco del contenedor: 138 ms).
#
# Medido sobre el handshake de T-008, verificando que devuelva el challenge:
#   validate_timestamps=1 (con cualquier revalidate_freq) : ~3.100 ms
#   validate_timestamps=0                                 : ~180 ms
#
# `revalidate_freq` no mueve la aguja: lo que pesa es validate_timestamps.
#
# Queda en 0 a proposito.
#
# >>> EL CODIGO QUE EDITES NO SE VE HASTA QUE REINICIES: <<<
# >>>   docker compose restart app worker   (~12 s)      <<<
#
# Si un cambio "no hace nada", es esto antes que un bug. En produccion
# (php-fpm, sin bind mount) tambien va 0, asi que no es una rareza de dev.
RUN printf '%s\n' \
    'opcache.enable=1' \
    'opcache.enable_cli=1' \
    'opcache.validate_timestamps=0' \
    'opcache.memory_consumption=256' \
    'opcache.max_accelerated_files=20000' \
    > /usr/local/etc/php/conf.d/zz-opcache.ini

WORKDIR /var/www/html

EXPOSE 8000
