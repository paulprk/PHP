# WhatsApi — API REST de mensajería instantánea

Seminario de Lenguajes, opción PHP, React y API REST — 2026
Primera entrega: API REST con Slim 4

## Grupo 2

### Integrantes

- Paul Falcon
- Matias Lissalde

## Tecnologías y librerías externas

El proyecto corre en contenedores Docker (PHP 8, MySQL 8 y phpMyAdmin). Las librerías de PHP se instalan con Composer y son solo dos; el resto son dependencias que Composer trae automáticamente.

| Librería | Versión instalada | Para qué se usa |
|---|---|---|
| `slim/slim` | 4.15.3 | Framework del proyecto: define las rutas (`$app->get`, `$app->post`, ...), los middlewares y el manejo de errores |
| `slim/psr7` | 1.8.0 | Implementación de los objetos `Request` y `Response` (PSR-7) que usa Slim para leer el pedido y armar la respuesta |

Dependencias que instala Composer solas (no se usan directamente en el código): `nikic/fast-route` (router de Slim), `psr/http-message`, `psr/http-factory`, `psr/http-server-handler`, `psr/http-server-middleware`, `psr/container`, `psr/log`, `fig/http-message-util` y `ralouphie/getallheaders`.

No se usan librerías para la base de datos: se accede con **PDO**, que viene incluido en PHP.

## Instalación

Requisitos: Docker y Docker Compose.

1. Crear el archivo `.env` a partir de `.env.dist`:

   ```bash
   cp .env.dist .env
   ```

2. Crear el volumen para la base de datos (el nombre es el valor de `DB_VOLUME`):

   ```bash
   docker volume create seminariophp
   ```

3. Levantar los servicios:

   ```bash
   docker compose up -d
   ```

   El servicio `composer` ejecuta `composer install` automáticamente la primera vez, por lo que no hace falta instalar las dependencias a mano. Si la carpeta `slim/vendor` no existe, se crea sola.

4. La base de datos se crea sola. El archivo `db/seminariophp.sql` está montado en `/docker-entrypoint-initdb.d`, y MySQL lo ejecuta automáticamente **la primera vez** que se crea el volumen, generando las tablas `usuario`, `chat` y `mensaje`. No hace falta importar nada a mano.

   Si más adelante se quiere empezar de cero (por ejemplo, porque cambió el esquema), hay que borrar y volver a crear el volumen:

   ```bash
   docker compose down
   docker volume rm seminariophp
   docker volume create seminariophp
   docker compose up -d
   ```

   También se puede importar a mano desde phpMyAdmin (<http://localhost:8080>), eligiendo la base `seminariophp` e importando `db/seminariophp.sql`.

5. La API queda disponible en <http://localhost> (puerto `SLIM_PORT` del `.env`).

### Otros comandos

```bash
docker compose down -v          # terminar los servicios
docker volume rm seminariophp   # eliminar la base de datos
```

### Usuario administrador

El enunciado indica que existe un usuario administrador, pero el script SQL no trae ninguno. Para crearlo: registrar un usuario con `POST /usuarios` y luego, en phpMyAdmin, poner `is_admin = 1` en su fila de la tabla `usuario`.

## Autenticación

1. `POST /login` con `username` y `password`. Si son correctos, la respuesta incluye el header `Authorization: Bearer <token>`.
2. En cada pedido que requiera login, enviar ese mismo header `Authorization: Bearer <token>`.
3. El token vence a los **5 minutos** y se renueva otros 5 minutos con cada pedido autorizado.
4. `POST /logout` borra el token.

## Endpoints

La API responde siempre JSON. Los errores de validación se devuelven como un objeto indexado por el nombre del campo, por ejemplo `{"username": "Debe empezar con @", "password": "..."}`.

| Método y ruta | Login | Quién puede | Descripción |
|---|---|---|---|
| `POST /login` | No | Cualquiera | Devuelve el token en el header `Authorization` |
| `POST /logout` | Sí | Usuario logueado | Borra el token |
| `POST /usuarios` | No | Cualquiera | Registra un usuario (`es_publico = 0`) |
| `GET /usuarios` | Opcional | Cualquiera | Lista usuarios con `search`, `order`, `limit`, `offset` y `total` |
| `GET /usuarios/{user_id}` | Sí | El mismo usuario o admin | Devuelve `username`, `nombre` y `es_publico` |
| `PUT /usuarios/{user_id}` | Sí | El mismo usuario o admin | Modifica `nombre`, `password` y/o `es_publico` |
| `DELETE /usuarios/{user_id}` | Sí | Solo admin | Elimina el usuario, sus chats y sus mensajes |

### Códigos de estado

| Código | Significado |
|---|---|
| 200 | La solicitud se procesó correctamente |
| 400 | Faltan datos, o son inválidos |
| 401 | Sin token, token inválido o vencido, o sin permiso para la acción |
| 404 | El recurso no existe |
| 409 | La operación no se pudo realizar |

### Listado de usuarios: `GET /usuarios`

Parámetros opcionales en la URL:

| Parámetro | Valor | Por defecto |
|---|---|---|
| `search` | Texto que debe contener el `username` o el `nombre` | sin filtro |
| `order` | `asc` o `desc`, aplicado sobre el `username` | `asc` |
| `limit` | Entero mayor a 0 | `10` |
| `offset` | Entero mayor o igual a 0 | `0` |

- Sin token (o con token inválido) se listan solo los usuarios con `es_publico = 1`.
- Con token válido se listan todos los usuarios, excepto el logueado.
- La respuesta incluye `total` (cantidad de registros que cumplen el filtro, independiente de `limit`) y `usuarios`.
- Si quien consulta es admin, cada usuario incluye además `cantidad_chats`.

## Decisiones de diseño

Cosas que el enunciado no define y que se resolvieron así:

- **Token en el header.** El login devuelve el token en el header `Authorization: Bearer <token>` y no en el cuerpo de la respuesta.
- **Un administrador no puede eliminarse a sí mismo** (409), para evitar dejar al sistema sin administradores.
- **Errores de base de datos.** Si falla la conexión o una consulta, se responde 409 (la operación no se pudo realizar).
- **Valores por defecto de la paginación:** `limit = 10` y `offset = 0`.
- **Contraseñas.** Se guardan con `password_hash` (nunca en texto plano) y se comparan con `password_verify`.
- **Dos middlewares de autenticación.** `$authMiddleware` exige token válido (401 si falta o es inválido). `$authOpcional` deja pasar al pedido como anónimo si no hay token o es inválido; se usa solo en `GET /usuarios`.

## Estructura del proyecto

```
slim/
├── index.php          Configuración de Slim y carga de las rutas
├── db.php             Conexión a la base con PDO
├── middleware.php     Autenticación por token
├── auth.php           Rutas /login y /logout
├── users.php          Rutas de /usuarios
├── validaciones.php   Funciones de validación y de permisos
└── composer.json      Dependencias
```
