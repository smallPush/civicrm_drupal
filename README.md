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
4. Accede a tu entorno en `http://localhost:8080`. Se abrirá la pantalla de instalación estándar de Drupal.
5. Durante la instalación de Drupal, los parámetros de base de datos coinciden con los que configuraste en tu archivo `.env`.

### Instalación de CiviCRM

Una vez que Drupal esté instalado:
1. Dirígete a la interfaz de administración (Extend) y activa los módulos de CiviCRM.
2. Completa los pasos de instalación que indique el asistente. La configuración de base de datos para CiviCRM apuntará al mismo servidor de MariaDB.

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
   - Configura allí todas las variables presentes en el `.env.example` (`DB_ROOT_PASSWORD`, `DB_USER`, `DB_PASSWORD`, `DB_NAME`, `DRUPAL_HASH_SALT`).
4. **Desplegar**:
   - Haz clic en **Deploy**. Dokploy leerá el `docker-compose.yml` e iniciará los contenedores de `web` y `db`, creando automáticamente la red interna.
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
