# Traigo · backend

API en Laravel 13 de la app **Traigo Equipo** (y, más adelante, de la tienda web
de Navidad). El contrato completo (endpoints, datos y reglas) está en el
repositorio de la app: `docs/api-contract.md`.

## Qué hay hecho

| Fase | Contenido | Estado |
|---|---|---|
| 1 | Base de la API e ingreso: `POST /auth/login`, `/auth/two-factor`, `/auth/two-factor/resend`, `/auth/logout` y `GET /me` | Hecha |
| 2 | Catálogo (`/catalog`) y ventas (`/orders`): registrar, listar, cancelar y subir el comprobante | Hecha |
| 3 | Inicio, equipo y mi semana | Pendiente |
| 4 | Almacén | Pendiente |
| 5 | Panel de Jime | Pendiente |
| 6 | «Más» y Dirección | Pendiente |

## Instalar en una PC de desarrollo

Requiere PHP 8.3 o superior (con `pdo_pgsql`) y PostgreSQL 13 o superior. Laragon
ya trae los dos.

```powershell
composer install
copy .env.example .env
php artisan key:generate
```

Crea la base `traigo` y completa `DB_*` en `.env`. Después:

```powershell
php artisan migrate --seed
```

Para entrar con el PIN `1234`, como en la demo de la app (solo con
`APP_ENV=local`). También pone un correo de prueba a Jime y Dirección:

```powershell
php artisan db:seed --class=LocalPinSeeder
```

El inventario empieza en 0 (el de verdad se cargará desde la app, en la fase 4).
Para poder vender mientras tanto, carga el stock de ejemplo de la demo (solo en
desarrollo):

```powershell
php artisan db:seed --class=LocalStockSeeder
```

Levanta la API. `0.0.0.0` permite entrar desde un celular de la misma red:

```powershell
php artisan serve --host=0.0.0.0 --port=8000
```

La app se conecta así (en el emulador, `10.0.2.2` es tu PC; en un celular real
usa la IP de tu PC):

```powershell
flutter run --dart-define=API_MODE=live --dart-define=API_BASE_URL=http://10.0.2.2:8000/api/v1
```

Con `MAIL_MAILER=log`, el código de Jime y Dirección se lee en
`storage/logs/laravel.log`. Mientras falten las fases 3 a 6, en la app
funcionan el ingreso, las pestañas Catálogo y Vender, el detalle del pedido y su
comprobante. A «Mis pedidos» se llega desde un pedido recién registrado (el
atajo del Inicio llega con la fase 3). El Inicio y las demás pantallas
responden «no encontrado».

## Antes de producción

- `APP_ENV=production`, `APP_DEBUG=false` y **HTTPS**.
- Correo real en `MAIL_*`. Con `MAIL_MAILER=log` la API **no envía** códigos en
  producción.
- El PIN de cada persona y el correo de Jime y Dirección:

```powershell
php artisan traigo:correo JIM-01 correo@ejemplo.com
```

```powershell
php artisan traigo:pin JIM-01
```

  El PIN se escribe sin que se vea. Cambiarlo cierra las sesiones de la persona.
  Las cuentas del equipo también las repone Jime desde la app (fase 6); las de
  Jime y Dirección, solo con este comando.
- Tareas programadas (borran códigos y tokens vencidos): ejecuta
  `php artisan schedule:run` cada minuto.
- **Comprobantes**: se guardan en `storage/app/private/comprobantes`. Esa
  carpeta tiene que entrar en el **respaldo**, junto con la base.
- PHP debe aceptar archivos de 5 MB: `upload_max_filesize` de 6M o más y
  `post_max_size` de 8M o más (por defecto PHP solo acepta 2M).
- Si el servidor está detrás de un proxy que pone el HTTPS, configura los
  proxies de confianza (`trustProxies`); si no, los enlaces de los
  comprobantes saldrían con `http`.

## Cómo está armado

- `routes/api.php`: todas las rutas, bajo `/api/v1`.
- `app/Services/Auth`: PIN con bloqueo y el segundo paso por correo.
- `app/Services/Sales`: precios (misma regla que la app), stock apartado,
  registrar y cancelar ventas y los comprobantes.
- `app/Exceptions`: errores en el formato del contrato
  (`{ "message", "code"?, "data"? }`) y en español. Los mensajes de validación
  están en `lang/es`.
- `app/Http/Middleware`: cuenta activa, permisos por rol, quién actúa (para el
  historial de la base) e `Idempotency-Key` para que un envío repetido se haga
  una sola vez.
- `config/traigo.php`: límites de seguridad (intentos, bloqueo, códigos).

La base de datos (`database/`) sale de los Excel de la campaña: ella también
impide romper las reglas de negocio y escribe el historial.

## Pruebas

Usan PostgreSQL y **borran las tablas** de la base de pruebas. Crea una aparte
una sola vez:

```powershell
createdb -U postgres traigo_test
```

```powershell
php artisan test
```

Solo corren sobre una base cuyo nombre termine en `_test`. Si tu PostgreSQL usa
otro puerto o contraseña, define `DB_PORT` o `DB_PASSWORD` antes.

```powershell
vendor\bin\pint
```
