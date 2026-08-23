# Despliegue a producción — Tlapalería Totoro

Runbook para instalar/actualizar el sistema en un servidor real. Hoy el sistema corre local en un equipo del negocio; este documento ya cubre también el salto a un VPS/hosting cuando llegue ese momento (marcado explícitamente donde aplica).

## 1. Requisitos de servidor

- PHP `^8.2` con extensiones estándar de Laravel (`ctype, curl, dom, fileinfo, filter, hash, mbstring, openssl, pcre, pdo, pdo_pgsql, session, tokenizer, xml`).
- PostgreSQL, con los binarios cliente `pg_dump` y `pg_restore` disponibles (para los respaldos — ver sección 5).
- Node/npm solo si vas a compilar el frontend en el propio servidor; si no, sube `public/build` ya compilado desde tu máquina (`npm run build`).
- Composer 2.

## 2. Instalación / actualización

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env   # solo en instalación nueva — ver sección 3 para qué llenar
php artisan key:generate   # solo si .env no trae ya un APP_KEY
php artisan migrate --force
php artisan storage:link
npm ci && npm run build   # si se compila en el servidor
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

`php artisan migrate --force` es seguro de re-correr (Laravel salta las migraciones ya aplicadas). La migración `2025_12_22_000000_seed_initial_data.php` crea los roles y la sucursal principal la primera vez — no hace falta correr seeders aparte en una instalación limpia.

## 3. Variables críticas de `.env` en producción

| Variable | Valor esperado | Por qué importa |
|---|---|---|
| `APP_ENV` | `production` | Activa comportamientos de producción (cookies seguras, etc.) |
| `APP_DEBUG` | `false` | **Nunca `true`** — con `true` cualquier error expone stack traces, queries SQL y rutas del servidor al público. Hay un aviso en el log (`AppServiceProvider::boot()`) si esta combinación queda mal puesta, pero corregirlo en `.env` es lo que realmente lo arregla. |
| `APP_URL` | URL pública real | Usado para generar links absolutos (reset de password, etc.) |
| `SESSION_SECURE_COOKIE` | vacío (se infiere `true` si `APP_ENV=production`) | Requiere que el sitio se sirva por HTTPS de verdad, o las sesiones no funcionarán |
| `TRUSTED_PROXIES` | vacío hoy (local); IPs del proxy o `*` cuando haya Nginx/balanceador delante (VPS) | Sin esto detrás de un proxy real, Laravel no detecta bien HTTPS y rompe `SESSION_SECURE_COOKIE` y la generación de URLs |
| `MAIL_MAILER` y demás `MAIL_*` | un proveedor real (SMTP/SES/Postmark/etc.) | **Pendiente hoy** (`MAIL_MAILER=log`) — sin esto, "olvidé mi contraseña" no envía nada, solo queda escrito en `storage/logs/laravel.log`. Decidir proveedor antes de depender del self-service de contraseña. |
| `BACKUP_PG_DUMP_PATH` | ruta al binario `pg_dump` | En Linux normalmente `pg_dump` basta; en Windows/Laragon debe ser la ruta completa al `.exe` |
| `BACKUP_RETENTION_DAYS` | `14` (ajustable) | Cuántos días se conservan los respaldos antes de borrarse automáticamente |
| `LOG_LEVEL` | `warning` o `error` (no `debug`) | `debug` en producción puede loguear payloads sensibles y crece sin límite |

## 4. Checklist post-deploy inmediato

- [ ] `APP_DEBUG=false` confirmado (abrir una URL inexistente y verificar que NO se ve un stack trace).
- [ ] `php artisan backup:database` corrido manualmente al menos una vez y el archivo aparece en `storage/app/private/backups/`.
- [ ] Mailer configurado, o aceptado conscientemente como pendiente (documentar quién resetea contraseñas manualmente mientras tanto).
- [ ] Si hay proxy/Nginx delante: `TRUSTED_PROXIES` configurado y `https://` funcionando sin advertencias de cookie insegura.

## 5. Respaldos de base de datos

### Comando manual

```bash
php artisan backup:database
```

Genera un archivo `.dump` (formato `pg_dump -Fc`, comprimido) en `storage/app/private/backups/`, y borra automáticamente los que superen `BACKUP_RETENTION_DAYS`.

### Restaurar un respaldo

```bash
pg_restore -h <host> -U <usuario> -d <base_de_datos> --clean --if-exists storage/app/private/backups/<archivo>.dump
```

Prueba esto al menos una vez contra una base de datos de prueba antes de confiar en que los respaldos sirven de verdad.

### Programarlo

El respaldo ya está declarado en `routes/console.php` (`Schedule::command('backup:database')->dailyAt('03:00')`), pero Laravel necesita que algo dispare `schedule:run` cada minuto para que el scheduler funcione:

**Ahora (Windows local, en el equipo del negocio):**

Más simple que mantener un proceso corriendo 24/7: crear una tarea directa en el **Programador de Tareas de Windows** que corra, una vez al día:

```
php artisan backup:database
```

(con el directorio de trabajo apuntando a la raíz del proyecto). No hace falta pasar por `schedule:run` para esto.

**Después (VPS/Linux):**

Entrada estándar de cron (recoge automáticamente el `Schedule::command` de `routes/console.php`, sin tocar nada más):

```
* * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1
```

## 6. Notas

- Los respaldos van a disco local por ahora (`storage/app/private/backups/`, ya excluido de git). Si el disco del servidor falla, se pierde también el respaldo — cuando se decida el hosting final, vale la pena copiar periódicamente esa carpeta a otro lugar (USB, red, o más adelante un disco `s3` — el driver ya está listo en `config/filesystems.php`, solo faltan credenciales).
- No hay CI configurado todavía: antes de mergear a `main`, correr `php artisan test` manualmente.
