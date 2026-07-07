# Drupal 11 + CiviCRM Base Project

Este es un proyecto base configurado para ejecutar **Drupal 11** totalmente integrado con **CiviCRM**. Utiliza Composer para la gestión de dependencias y Docker para levantar un entorno listo para producción (o desarrollo). También está preparado para ser desplegado fácilmente en **Dokploy**.

## Estructura de Directorios

- `composer.json` y `composer.lock`: Gestionan todas las dependencias del proyecto (Drupal, CiviCRM, librerías adicionales).
- `Dockerfile`: Configuración para construir la imagen de PHP 8.3 con Apache, instalando todas las extensiones PHP necesarias y copiando el código fuente.
- `docker-compose.yml`: Archivo de configuración para levantar el contenedor web (Drupal+CiviCRM) y la base de datos (MariaDB). Incluye volúmenes para persistencia y *health checks*.
- `.env` / `.env.example`: Archivos que almacenan las variables de entorno de la base de datos y la sal (salt) de encriptación de Drupal.
- `web/`: (Generado automáticamente tras instalación) Directorio raíz (DocumentRoot) donde residen los archivos públicos de Drupal y CiviCRM.

## Requisitos

- Docker y Docker Compose
- Composer (opcional para desarrollo local fuera del contenedor)

## Configuración e Instalación Local

1. Copia el archivo `.env.example` a `.env`:
   ```bash
   cp .env.example .env
   ```
2. Modifica los valores en el archivo `.env` según sea necesario (asegúrate de generar un hash aleatorio para `DRUPAL_HASH_SALT`).
3. Construye y levanta los contenedores:
   ```bash
   docker compose up -d --build
   ```
4. Accede a tu entorno en `http://localhost:8080`. Si necesitas cambiar el puerto local, ajusta `HOST_PORT` en `.env`. Se abrirá la pantalla de instalación estándar de Drupal.
5. Durante la instalación de Drupal, los parámetros de base de datos coinciden con los que configuraste en tu archivo `.env`.

### Instalación en una Base de Datos Externa (vía Drush)

Si deseas instalar Drupal utilizando una base de datos externa a través de la línea de comandos, puedes usar **Drush**. Ejecuta el siguiente comando (asegúrate de que las variables de entorno estén configuradas en tu archivo `.env`):

```bash
docker compose exec web ./vendor/bin/drush site:install standard --db-url="mysql://${DB_USER}:${DB_PASSWORD}@${DB_HOST}/${DB_NAME}" --site-name="My Drupal Site" -y
```

### Instalación de CiviCRM

Una vez que Drupal esté instalado:
1. Dirígete a la interfaz de administración (Extend) y activa los módulos de CiviCRM.
2. Completa los pasos de instalación que indique el asistente. La configuración de base de datos para CiviCRM apuntará al mismo servidor de MariaDB.

### Eliminar CiviCRM si no se instaló correctamente

Si la instalación de CiviCRM falla o queda incompleta, primero intenta desactivar los módulos desde Drupal.

En local, ejecuta:

```bash
docker compose exec web ./vendor/bin/drush pm:uninstall -y webform_civicrm civicrm
```

En Dokploy, abre la terminal del contenedor `web` y ejecuta el mismo comando sin `docker compose exec web`:

```bash
./vendor/bin/drush pm:uninstall -y webform_civicrm civicrm
```

Si CiviCRM quedó a medio instalar y el comando anterior falla, haz una copia de seguridad de la base de datos y usa este comando de limpieza desde la terminal del contenedor `web` en Dokploy para quitar los módulos de la configuración activa de Drupal:

```bash
./vendor/bin/drush php:eval '$config = \Drupal::configFactory()->getEditable("core.extension"); foreach (["webform_civicrm", "civicrm"] as $module) { $config->clear("module.$module"); \Drupal::service("keyvalue")->get("system.schema")->delete($module); } $config->save();'
./vendor/bin/drush cr
```

Si también quieres quitar CiviCRM del código del proyecto, elimina los paquetes de Composer y vuelve a construir la imagen localmente:

```bash
composer remove drupal/webform_civicrm civicrm/civicrm-drupal-8 civicrm/civicrm-core civicrm/civicrm-packages civicrm/civicrm-asset-plugin
docker compose up -d --build
```

En Dokploy, no hagas `composer remove` directamente dentro del contenedor para un cambio permanente: el cambio se perderá al redeplegar. Haz el cambio en `composer.json`/`composer.lock`, súbelo al repositorio y redepliega la aplicación.



## Persistencia y Configuración (Importante para Dokploy)

Al desplegar en sistemas basados en contenedores efímeros (como Dokploy):

*   **El contenedor web se recrea desde cero en cada deploy**, copiando los archivos definidos en la imagen (`Dockerfile`).
*   **Cualquier cambio realizado en los archivos del contenedor y que no esté en un volumen de Docker, se perderá**.

### Archivos de Configuración (`settings.php` y `civicrm.settings.php`)
*   No debes editar estos archivos manualmente dentro del contenedor en el servidor de producción. Si lo haces, tus cambios se perderán en el próximo despliegue.
*   La configuración debe provenir del repositorio de Git y de variables de entorno (almacenadas en Dokploy o en el `.env`).
*   **`civicrm.settings.php`:** El archivo ya se ha incluido en el código fuente de la aplicación basándose en variables de entorno para las credenciales de BD. Esto asegura que la configuración sobreviva a los redespliegues. Puedes ajustar las variables en tu `.env` o en Dokploy (ej. `CIVICRM_SITE_KEY`, `CIVICRM_DB_HOST`, etc).

### Volúmenes (Archivos Subidos)
*   La ruta `/var/www/html/web/sites/default/files` está persistida usando el volumen `drupal_data` en el `docker-compose.yml`. Todos los archivos públicos subidos por los usuarios, directorios `civicrm/templates_c`, `civicrm/ConfigAndLog`, etc., vivirán aquí y **NO se perderán**.
*   El sistema de **archivos privados** (configurado en `sites/default/files/private`) **tampoco se eliminará ni perderá datos**, ya que reside dentro del mismo directorio `files/` que está siendo persistido por el volumen `drupal_data`.

## Despliegue en Dokploy

Este proyecto está optimizado para ser desplegado en **Dokploy** u otras plataformas basadas en Docker Compose.

### Instrucciones paso a paso para Dokploy:

1. **Crear la aplicación**:
   - Inicia sesión en Dokploy y navega a la sección **Applications** o **Compose**.
   - Crea un nuevo proyecto del tipo "Docker Compose".
2. **Repositorio**:
   - Conecta el repositorio Git de este proyecto.
3. **Variables de Entorno**:
   - Ve a la pestaña **Environment Variables**.
   - Configura allí todas las variables presentes en el `.env.example`:
     - `HOST_PORT`: (Opcional, solo para acceso directo/local) Puerto publicado en el host, por defecto 8080. No es el puerto interno del contenedor.
     - `DB_ROOT_PASSWORD`: Contraseña root de MariaDB.
     - `DB_USER`: Usuario de la base de datos para Drupal y CiviCRM.
     - `DB_PASSWORD`: Contraseña del usuario de la base de datos.
     - `DB_NAME`: Nombre de la base de datos principal.
     - `DRUPAL_HASH_SALT`: Hash aleatorio y seguro para encriptación en Drupal.
     - `CIVICRM_SITE_KEY`: (Requerido para CiviCRM) Clave de sitio segura.
     - `CIVICRM_UF_BASEURL`: (Opcional recomendado en producción) URL pública del sitio, por ejemplo `https://tu-dominio.example/`.
     - Variables adicionales de BD de CiviCRM si utilizas bases separadas (`CIVICRM_DB_HOST`, `CIVICRM_DB_USER`, `CIVICRM_DB_PASSWORD`, `CIVICRM_DB_NAME`).
4. **Desplegar**:
   - Haz clic en **Deploy**. Dokploy leerá el `docker-compose.yml` e iniciará los contenedores de `web` y `db`, creando automáticamente la red interna.
   - En la configuración de dominio/proxy de Dokploy, apunta el dominio al servicio `web` usando el puerto interno `80`. No uses `8080` como puerto del contenedor; `8080` solo es el puerto publicado localmente por defecto.
   - Los directorios `/var/www/html/web/sites/default/files` y `/var/lib/mysql` utilizarán los volúmenes configurados para persistir información (archivos subidos y base de datos) aunque se reinicien los contenedores.
5. **Comprobación (Health Checks)**:
   - Dokploy podrá monitorizar la salud de ambos contenedores, ya que `docker-compose.yml` expone sus respectivos `healthcheck`.

## Actualización y Mantenimiento

Para actualizar Drupal o CiviCRM:

1. Modifica la versión de los paquetes en el `composer.json` localmente.
2. Ejecuta `composer update drupal/core-recommended civicrm/civicrm-core` (o el paquete que desees actualizar).
3. Sube el `composer.lock` modificado al repositorio.
4. Redepliega la aplicación en Dokploy. En la reconstrucción del contenedor, los nuevos paquetes serán descargados.

## Seguridad y Producción

- **Variables sensibles**: Nunca almacenes contraseñas directamente en tu `docker-compose.yml`. Para eso se utilizan las variables de entorno inyectadas mediante Dokploy o archivos `.env`.
- **DRUPAL_HASH_SALT**: Genera siempre un hash largo y aleatorio para uso en producción.
- **Base de datos separada (opcional)**: En producción, puedes considerar utilizar dos bases de datos separadas (una para Drupal, otra para CiviCRM) editando la configuración y agregando otra base de datos en el archivo de Compose o utilizando un RDS/servidor gestionado externo, y ajustando las variables `CIVICRM_DB_*`.
