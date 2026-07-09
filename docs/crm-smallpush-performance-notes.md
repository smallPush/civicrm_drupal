# CRM Smallpush Performance Notes

Fecha: 2026-07-09

Servicio PHP/Drupal/CiviCRM:

```text
crm-smallpush-php-server-1k3nmw
```

Servicio MariaDB:

```text
crm-smallpush-mysql-8hq7mu
```

## Resumen

La app es Drupal con CiviCRM sobre Apache/PHP 8.3 y MariaDB 11. La lentitud observada no parecia venir de saturacion sostenida de CPU/RAM. El principal problema era configuracion de rendimiento conservadora en Drupal y MariaDB con valores por defecto.

Tambien se encontro un problema de permisos en CiviCRM:

```text
/var/www/html/web/sites/default/files/civicrm/templates_c
```

CiviCRM no podia escribir cache compilada en ese directorio y eso provoco errores 500 tras reconstruir cache. Se corrigio el propietario/permisos del arbol `files/civicrm`.

## Cambios Aplicados

### Drupal

Se aplicaron estos cambios con Drush dentro del contenedor PHP:

```bash
vendor/bin/drush cset system.performance css.preprocess 1 -y
vendor/bin/drush cset system.performance js.preprocess 1 -y
vendor/bin/drush cset system.performance cache.page.max_age 900 -y
vendor/bin/drush cr
```

Estado esperado:

```yaml
cache:
  page:
    max_age: 900
css:
  preprocess: true
  gzip: true
js:
  preprocess: true
  gzip: true
```

Impacto:

```text
Menos trabajo por request anonima.
Menos assets CSS/JS separados.
Mejor comportamiento con cache de pagina.
```

### Permisos CiviCRM

Se corrigio:

```bash
mkdir -p /var/www/html/web/sites/default/files/civicrm/templates_c
chown -R www-data:www-data /var/www/html/web/sites/default/files/civicrm
chmod -R u+rwX,g+rwX /var/www/html/web/sites/default/files/civicrm
vendor/bin/drush cr
```

Error que resolvia:

```text
Cannot rename /tmp/CachedExtLoader... to /var/www/html/web/sites/default/files/civicrm/templates_c/... Permission denied
```

### MariaDB Runtime

Se aplicaron cambios runtime con root de MariaDB:

```sql
SET GLOBAL innodb_buffer_pool_size=536870912;
SET GLOBAL tmp_table_size=67108864;
SET GLOBAL max_heap_table_size=67108864;
SET GLOBAL long_query_time=2;
SET GLOBAL slow_query_log=ON;
```

Estado esperado:

```text
innodb_buffer_pool_size = 512M
tmp_table_size = 64M
max_heap_table_size = 64M
long_query_time = 2
slow_query_log = ON
```

Importante: estos cambios runtime se pierden si el contenedor MariaDB se recrea. Hay que persistirlos en la configuracion de Dockploy/compose/comando del servicio MariaDB.

## Verificacion Realizada

Antes de cambios, Drupal tenia:

```yaml
cache.page.max_age: 0
css.preprocess: false
js.preprocess: false
```

MariaDB tenia:

```text
innodb_buffer_pool_size = 128M
tmp_table_size = 16M
max_heap_table_size = 16M
slow_query_log = OFF
```

Despues de corregir permisos y reconstruir cache:

```text
fixed_1 http=200 total=3.818509  # primera carga tras cache rebuild, recompila CiviCRM/Drupal
fixed_2 http=200 total=0.010642
fixed_3 http=200 total=0.032681
fixed_4 http=200 total=0.006213
fixed_5 http=200 total=0.015224
```

La primera request despues de `drush cr` puede ser lenta. Las siguientes quedan rapidas localmente.

## Como Verificar Rapidamente

Identificar contenedores actuales:

```bash
docker ps --format '{{.Names}}\t{{.Status}}\t{{.Image}}' | grep 'crm-smallpush'
```

Comprobar Drupal performance config:

```bash
docker exec "<php-container>" sh -c 'vendor/bin/drush cget system.performance --format=yaml'
```

Medir respuesta local sin Traefik:

```bash
docker exec "<php-container>" sh -c 'for i in 1 2 3 4 5; do curl -s -o /dev/null -w "http=%{http_code} total=%{time_total} starttransfer=%{time_starttransfer}\n" http://127.0.0.1/; done'
```

Comprobar MariaDB tuning:

```bash
docker exec "<mariadb-container>" sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -NBe "SHOW GLOBAL VARIABLES WHERE Variable_name IN ('\''innodb_buffer_pool_size'\'','\''tmp_table_size'\'','\''max_heap_table_size'\'','\''long_query_time'\'','\''slow_query_log'\'');"'
```

Revisar slow queries:

```bash
docker exec "<mariadb-container>" sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -NBe "SHOW GLOBAL STATUS LIKE '\''Slow_queries'\'';"'
```

## Persistencia Recomendada

Persistir MariaDB en Dockploy/compose/comando del servicio con equivalentes a:

```text
--innodb-buffer-pool-size=512M
--tmp-table-size=64M
--max-heap-table-size=64M
--slow-query-log=ON
--long-query-time=2
```

Si el servidor crece o la base aumenta, considerar `innodb_buffer_pool_size=768M` o `1G`, pero solo si hay RAM libre suficiente.

## Mejoras Pendientes

### PHP OPcache

OPcache esta activo, pero con `opcache.validate_timestamps=On`. Para produccion se recomienda crear un `.ini` persistente en la imagen o configuracion PHP:

```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
realpath_cache_size=4096K
realpath_cache_ttl=600
```

Con `validate_timestamps=0`, hay que reiniciar PHP/Apache tras cada deploy.

### Redis

Drupal/CiviCRM suele mejorar con cache persistente fuera de MariaDB. Recomendado:

```text
Redis dedicado + modulo redis de Drupal + backend de cache en settings.php
```

No aplicar sin revisar dependencias del proyecto y proceso de deploy.

### Apache/PHP-FPM

Actualmente Apache usa `mpm_prefork`. Funciona, pero no es lo mas eficiente. A medio plazo considerar PHP-FPM con Apache event o Nginx si el trafico crece.

## Riesgos

`cache.page.max_age=900` mejora paginas anonimas. Si alguna pagina publica debe cambiar en tiempo real, puede verse cacheada hasta 15 minutos. Para usuarios autenticados/admin el impacto de page cache es menor.

Los cambios runtime de MariaDB no sobreviven a recreacion del contenedor.

Los permisos corregidos dentro del contenedor pueden perderse si `sites/default/files` no esta en volumen persistente o si la imagen se recrea con permisos incorrectos.
