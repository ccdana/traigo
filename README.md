# Traigo · backend

Backend Laravel 13 de la app **Traigo Equipo** y de la tienda web de TRAIGO.
El contrato de la API móvil está en el repositorio de la app, en
`docs/api-contract.md`.

## Qué hay hecho

| Fase | Contenido | Estado |
|---|---|---|
| 1 | Base de la API e ingreso: `POST /auth/login`, `/auth/two-factor`, `/auth/two-factor/resend`, `/auth/logout` y `GET /me` | Hecha |
| 2 | Catálogo (`/catalog`) y ventas (`/orders`): registrar, listar, cancelar y subir el comprobante | Hecha |
| 3 | Inicio (`/home`), «Equipo» (`/team/week`), «Mi semana» (`/me/week`), evidencia y entregas de Sistemas | Hecha |
| 4 | Almacén: despachos (`/warehouse`), caja del día y cierres (`/cash`), stock y conteos (`/inventory`) | Hecha |
| 5 | Panel de Jime (`/control`): resumen y pendientes, pagos, evidencia y valores, cajas, entregas, devoluciones y duplicados, cumplimiento, cierre semanal, bono y comisiones | Hecha |
| 6 | «Más» y Dirección: precios y sus cambios, autorizaciones (`/management`), historial, reportes en Excel, estado de respaldos y accesos | Hecha |

La tienda web usa el mismo catálogo, pedidos e inventario que la app.
El cliente realiza el pago por QR y carga su comprobante. Jime lo revisa y
almacén prepara y despacha el pedido.

## Instalar en una PC de desarrollo

Requiere PHP 8.3 o superior (con `pdo_pgsql` y `zip`, para los Excel) y
PostgreSQL 13 o superior. Laragon ya trae todo.

```powershell
composer install
copy .env.example .env
php artisan key:generate
```

Crea la base `traigo` y completa `DB_*` en `.env`. Después:

```powershell
php artisan migrate --seed
```

Si una instalación de desarrollo se interrumpe al crear los tipos de
PostgreSQL, vuelve a ejecutar `php artisan migrate --seed`. La migración
reutiliza los tipos que coinciden con el esquema. Usa
`php artisan migrate:fresh --seed` solo para reiniciar una base descartable,
porque borra sus tablas y datos.

Para abrir la tienda en la raíz del servidor, instala y compila sus recursos:

```powershell
npm ci
npm run build
```

La tienda requiere que Dirección cargue el QR de pago en el panel y que haya
stock disponible para recibir pedidos.

Para entrar con el PIN `1234`, como en la demo de la app (solo con
`APP_ENV=local`). También pone un correo de prueba a Jime y Dirección:

```powershell
php artisan db:seed --class=LocalPinSeeder
```

El inventario empieza en 0: el de verdad se carga desde la app (almacén →
Stock, «Inventario inicial»). Para probar ventas rápido en desarrollo, puedes
cargar el stock de ejemplo de la demo:

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
`storage/logs/laravel.log`.

La caja diaria empieza el **1 de diciembre de 2026** (primer día de la
campaña). Antes, «Caja» muestra lo cobrado hoy, pero al enviarla responde «Ese
día no tiene caja.».

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
  Las cuentas del equipo también las repone Jime desde «Más» → Accesos; las de
  Jime y Dirección, solo con este comando.
- Tareas programadas (borran códigos y tokens vencidos): ejecuta
  `php artisan schedule:run` cada minuto.
- **Archivos**: comprobantes, evidencias y entregas se guardan en
  `storage/app/private`. Esa carpeta tiene que entrar en el **respaldo**, junto
  con la base.
- **Respaldos**: el backend no los hace. Que el hosting (o un script) respalde
  cada día la base y esa carpeta y, al terminar, ejecute:

```powershell
php artisan traigo:respaldo
```

  Cada vez que se pruebe restaurar uno (el Excel lo exige):

```powershell
php artisan traigo:respaldo restauracion
```

  Con `--fecha="2026-12-06 03:00"` se anota otra hora. La app lo muestra en
  «Más»; mientras no se anote, dice que no hay datos.
- PHP debe aceptar archivos de 5 MB: `upload_max_filesize` de 6M o más y
  `post_max_size` de 8M o más (por defecto PHP solo acepta 2M).
- Si el servidor está detrás de un proxy que pone el HTTPS, configura los
  proxies de confianza (`trustProxies`); si no, los enlaces de los
  comprobantes saldrían con `http`.

## Cómo está armado

- `routes/web.php`: tienda pública y panel web del equipo.
- `routes/api.php`: rutas de la app móvil, bajo `/api/v1`.
- `app/Services/Auth`: PIN con bloqueo y el segundo paso por correo.
- `app/Services/Sales`: precios (misma regla que la app), stock apartado,
  registrar y cancelar ventas y los comprobantes.
- `app/Services/Team`: Inicio, «Equipo», evidencia y entregas. El puntaje y
  el «cumple» salen de las vistas de la base (las fórmulas del Excel).
  `WeekLock` congela la semana mientras su cierre está con Dirección.
- `app/Services/Control`: el panel de Jime. La semana que se muestra, la
  conciliación, las validaciones, las devoluciones, el cierre semanal con la
  foto del bono, las liquidaciones de comisiones, los cambios de precio, el
  historial en palabras y el estado de los respaldos.
- `app/Services/Management`: lo que Dirección autoriza o devuelve (bono,
  comisiones, precios y promociones).
- `app/Services/Reports`: los tres Excel, con `openspout`. Los textos van como
  texto para que nada de lo que escribe la gente se vuelva una fórmula.
- `app/Services/Cash`: la caja del día y su envío. Cada cobro queda en un solo
  cierre; el que llega después de enviarlo pasa al siguiente.
- `app/Services/Warehouse`: despachos (al entregar sale del stock, con su
  movimiento en el kardex; de un combo salen sus componentes), entradas, ajustes
  y conteos.
- `app/Exceptions`: errores en el formato del contrato
  (`{ "message", "code"?, "data"? }`) y en español. Los mensajes de validación
  están en `lang/es`.
- `app/Http/Middleware`: cuenta activa, permisos por rol, quién actúa (para el
  historial de la base) e `Idempotency-Key` para que un envío repetido se haga
  una sola vez.
- `config/traigo.php`: límites de seguridad (intentos, bloqueo, códigos).

La base de datos (`database/`) sale de los Excel de la campaña: ella también
impide romper las reglas de negocio y escribe el historial.

## Migración del esquema relacional

Las claves internas de líneas de pedido, comisiones y liquidaciones, detalles
de caja, atributos e imágenes de combo y asistencia usan identidades bigint.
Los UUID del contrato móvil, los tokens de seguimiento y las claves de
idempotencia conservan su formato. La tabla de equivalencias
`legacy_internal_identifiers` permite rastrear un identificador histórico.

- **1FN.** Las líneas, los cobros, los atributos y las evidencias se guardan
  como filas. El JSON de auditoría conserva los documentos de antes y después.
- **2FN.** El resultado semanal usa la combinación de semana e indicador.
  El responsable se obtiene del indicador. La meta y peso guardados son los
  efectivos de esa semana.
- **3FN.** Cada comisión tiene una fuente. Se llega al vendedor por el pedido
  de la línea o por el pedido del ajuste sin línea. Los importes, tasas y
  estados históricos siguen en sus respectivos hechos.
- **4FN.** Imágenes, atributos, componentes, evidencias y cobros permanecen
  en relaciones con sus propias claves. Los totales se agregan por separado.
- **5FN.** Las asignaciones persona, sede e intervalo representan una
  autorización específica. Los componentes pertenecen a la línea que se
  vendió. Deducir esas combinaciones cruzando listas independientes introduciría
  datos que nunca se autorizaron ni se vendieron.

Para aplicar en un entorno con datos, probar primero un respaldo restaurado
de la base y de los archivos privados. Comparar ventas, cobros, saldos por
SKU, comisiones por período, KPI y cierres. La conversión de tipos toma
bloqueos exclusivos. Planificar una ventana, detener las escrituras y aplicar
el código y las migraciones en conjunto. Para revertir los cambios de claves
y de estructura, restaurar ese respaldo con la versión anterior del código.

En combos anteriores a la migración, completar `order_item_components`
solo con evidencia del pedido original. El backend pide esa verificación
antes de afectar el inventario de un combo sin composición histórica.
Cada semana nueva requiere su política y sus integrantes explícitos.
Antes de publicar una nueva política, crear otro código y sus escalones
para preservar las condiciones de semanas ya asignadas.

## Asistencia del equipo

En **Inicio → Asistencia** (o **Más → Asistencia** para Jime y Dirección), la
persona ve sus turnos recientes y puede marcar entrada y salida durante el
turno. La app pide una lectura puntual al seleccionar la acción. La API
verifica la vigencia, la precisión y la distancia a la sede. El servidor
registra la hora y el resultado, sin guardar las coordenadas del teléfono.

Desde la consola del servidor, configura la sede con sus coordenadas reales
y asigna los turnos en hora de Bolivia. En el primer comando, reemplaza
`LATITUD` y `LONGITUD` por números decimales. El código final identifica a
la persona responsable de la configuración.

```powershell
php artisan traigo:asistencia-sede --precision=30 -- PRINCIPAL "Sede principal" LATITUD LONGITUD 100 JIM-01
php artisan traigo:asistencia-turno ALI-04 PRINCIPAL "2026-12-01 08:00" "2026-12-01 17:00" JIM-01
```

El separador `--` permite pasar coordenadas negativas. Coloca las opciones
antes de ese separador.
Ejecutar de nuevo el comando de sede guarda una nueva versión del rango.
Por defecto, la distancia más el margen de precisión deben caber dentro del
radio. Los turnos pueden cruzar medianoche.

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
