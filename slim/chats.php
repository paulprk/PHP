<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** @var \Slim\App $app */
/** @var \Closure $authMiddleware */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/middleware.php';

/** Escribe una respuesta JSON manteniendo el estilo del resto de la API. */
function responderChat(Response $response, array $datos, int $status = 200): Response
{
    $response->getBody()->write(json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
}

/** Busca el chat más reciente entre dos usuarios, independientemente de quién lo creó. */
function buscarChatEntreUsuarios(PDO $db, int $usuarioA, int $usuarioB): array|false
{
    $stmt = $db->prepare(
        'SELECT id, creado_por, usuario_id, nombre, descripcion, color, esta_bloqueado
         FROM chat
         WHERE (creado_por = :a1 AND usuario_id = :b1)
            OR (creado_por = :b2 AND usuario_id = :a2)
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([
        'a1' => $usuarioA,
        'b1' => $usuarioB,
        'b2' => $usuarioB,
        'a2' => $usuarioA,
    ]);
    return $stmt->fetch();
}

// POST /chats/{user_id}
// Crea un chat y su primer mensaje si no existe, o agrega un mensaje a un chat existente.
$app->post('/chats/{user_id}', function (Request $request, Response $response, array $args) {
    $logueado = (int) $request->getAttribute('auth_id');
    $userId = filter_var($args['user_id'] ?? null, FILTER_VALIDATE_INT);
    $data = $request->getParsedBody() ?? [];
    $mensaje = $data['mensaje'] ?? null;

    if ($userId === false || $userId < 1) {
        return responderChat($response, ['user_id' => 'Debe ser un ID válido'], 400);
    }
    if (!is_string($mensaje) || trim($mensaje) === '' || mb_strlen($mensaje) < 1 || mb_strlen($mensaje) > 255) {
        return responderChat($response, ['mensaje' => 'Debe ser un texto de 1 a 255 caracteres'], 400);
    }

    try {
        $db = getDB();
        $db->beginTransaction();

        $stmt = $db->prepare('SELECT id, nombre FROM usuario WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $destinatario = $stmt->fetch();
        if (!$destinatario) {
            $db->rollBack();
            return responderChat($response, ['error' => 'Usuario no encontrado'], 404);
        }

        $chat = buscarChatEntreUsuarios($db, $logueado, (int) $userId);
        if (!$chat) {
            $stmt = $db->prepare(
                'INSERT INTO chat (creado_por, usuario_id, nombre, descripcion)
                 VALUES (:creado_por, :usuario_id, :nombre, \'\')'
            );
            $stmt->execute([
                'creado_por' => $logueado,
                'usuario_id' => $userId,
                'nombre' => $destinatario['nombre'],
            ]);
            $chatId = (int) $db->lastInsertId();
        } else {
            $chatId = (int) $chat['id'];
            if ((int) $chat['esta_bloqueado'] === 1) {
                $db->rollBack();
                return responderChat($response, ['error' => 'No se pueden enviar mensajes a un chat bloqueado'], 409);
            }

            $stmt = $db->prepare('SELECT COUNT(*) FROM mensaje WHERE chat_id = :chat_id');
            $stmt->execute(['chat_id' => $chatId]);
            $cantidadMensajes = (int) $stmt->fetchColumn();

            // Si hay exactamente un mensaje inicial, solo puede responder el otro participante.
            if ($cantidadMensajes === 1) {
                $stmt = $db->prepare(
                    'SELECT enviado_por FROM mensaje
                     WHERE chat_id = :chat_id
                     ORDER BY created_at ASC, id ASC
                     LIMIT 1'
                );
                $stmt->execute(['chat_id' => $chatId]);
                $emisorInicial = (int) $stmt->fetchColumn();
                if ($emisorInicial === $logueado) {
                    $db->rollBack();
                    return responderChat($response, ['error' => 'El otro usuario debe responder antes de enviar otro mensaje'], 409);
                }
            }
        }

        $stmt = $db->prepare('INSERT INTO mensaje (chat_id, enviado_por, texto) VALUES (:chat_id, :enviado_por, :texto)');
        $stmt->execute([
            'chat_id' => $chatId,
            'enviado_por' => $logueado,
            'texto' => $mensaje,
        ]);
        $mensajeId = (int) $db->lastInsertId();

        $db->commit();
        return responderChat($response, [
            'mensaje' => 'Mensaje enviado correctamente',
            'chat_id' => $chatId,
            'mensaje_id' => $mensajeId,
        ], 201);
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        return responderChat($response, ['error' => 'No se pudo enviar el mensaje'], 409);
    }
})->add($authMiddleware);

// GET /chats
// Lista los chats del usuario y el último mensaje de cada uno.
$app->get('/chats', function (Request $request, Response $response) {
    $logueado = (int) $request->getAttribute('auth_id');

    try {
        $db = getDB();
        $stmt = $db->prepare(
            'SELECT
                c.id,
                c.nombre,
                c.descripcion,
                c.esta_bloqueado,
                c.creado_por,
                c.usuario_id,
                CASE WHEN c.creado_por = :id_nombre THEN u2.nombre ELSE u1.nombre END AS nombre_otro_usuario,
                m.texto AS ultimo_mensaje,
                m.enviado_por AS ultimo_mensaje_enviado_por,
                m.created_at AS fecha_ultimo_mensaje
             FROM chat c
             INNER JOIN usuario u1 ON u1.id = c.creado_por
             INNER JOIN usuario u2 ON u2.id = c.usuario_id
             LEFT JOIN mensaje m ON m.id = (
                SELECT m2.id
                FROM mensaje m2
                WHERE m2.chat_id = c.id
                ORDER BY m2.created_at DESC, m2.id DESC
                LIMIT 1
             )
             WHERE c.creado_por = :id_creador OR c.usuario_id = :id_participante
             ORDER BY m.created_at DESC, c.id DESC'
        );
        $stmt->execute([
            'id_nombre' => $logueado,
            'id_creador' => $logueado,
            'id_participante' => $logueado,
        ]);
        return responderChat($response, ['chats' => $stmt->fetchAll()]);
    } catch (PDOException $e) {
        return responderChat($response, ['error' => 'No se pudieron obtener los chats'], 409);
    }
})->add($authMiddleware);

// PUT /chats/{user_id}
// Modifica los datos del chat entre el usuario autenticado y el usuario indicado.
$app->put('/chats/{user_id}', function (Request $request, Response $response, array $args) {
    $logueado = (int) $request->getAttribute('auth_id');
    $userId = filter_var($args['user_id'] ?? null, FILTER_VALIDATE_INT);
    if ($userId === false || $userId < 1) {
        return responderChat($response, ['user_id' => 'Debe ser un ID válido'], 400);
    }

    $data = $request->getParsedBody() ?? [];
    $permitidos = ['nombre', 'descripcion', 'esta_bloqueado', 'color'];
    $camposPresentes = array_values(array_intersect($permitidos, array_keys($data)));
    if (!$camposPresentes) {
        return responderChat($response, ['error' => 'Debe indicar al menos un campo para modificar'], 400);
    }

    $errores = [];
    if (array_key_exists('nombre', $data)) {
        if (!is_string($data['nombre']) || mb_strlen($data['nombre']) < 3 || mb_strlen($data['nombre']) > 15 || !preg_match('/^[\p{L}\p{N}]+$/u', $data['nombre'])) {
            $errores['nombre'] = 'Debe contener entre 3 y 15 letras o números, sin espacios';
        }
    }
    if (array_key_exists('descripcion', $data)) {
        if (!is_string($data['descripcion']) || mb_strlen($data['descripcion']) < 20 || mb_strlen($data['descripcion']) > 40 || !preg_match('/^[\p{L}\p{N}]+$/u', $data['descripcion'])) {
            $errores['descripcion'] = 'Debe contener entre 20 y 40 letras o números, sin espacios';
        }
    }
    if (array_key_exists('color', $data)) {
        if (!is_string($data['color']) || !preg_match('/^#[0-9a-fA-F]{6}$/', $data['color'])) {
            $errores['color'] = 'Debe ser un código hexadecimal de seis dígitos, por ejemplo #FF8800';
        }
    }

    $bloqueadoSolicitado = null;
    if (array_key_exists('esta_bloqueado', $data)) {
        $valor = $data['esta_bloqueado'];
        if (is_bool($valor)) {
            $bloqueadoSolicitado = $valor ? 1 : 0;
        } elseif (in_array($valor, [0, 1, '0', '1'], true)) {
            $bloqueadoSolicitado = (int) $valor;
        } elseif (is_string($valor) && in_array(strtolower($valor), ['true', 'false'], true)) {
            $bloqueadoSolicitado = strtolower($valor) === 'true' ? 1 : 0;
        } else {
            $errores['esta_bloqueado'] = 'Debe ser true o false';
        }
    }
    if ($errores) {
        return responderChat($response, $errores, 400);
    }

    try {
        $db = getDB();
        $db->beginTransaction();
        $chat = buscarChatEntreUsuarios($db, $logueado, (int) $userId);
        if (!$chat) {
            $db->rollBack();
            return responderChat($response, ['error' => 'No existe un chat entre esos usuarios'], 404);
        }

        if ((int) $chat['esta_bloqueado'] === 1 && $bloqueadoSolicitado === 0) {
            $db->rollBack();
            return responderChat($response, ['esta_bloqueado' => 'Un chat bloqueado no se puede desbloquear'], 409);
        }

        $actualizaciones = [];
        $parametros = ['id_chat' => (int) $chat['id']];
        foreach (['nombre', 'descripcion', 'color'] as $campo) {
            if (array_key_exists($campo, $data)) {
                $actualizaciones[] = "$campo = :$campo";
                $parametros[$campo] = $data[$campo];
            }
        }
        if ($bloqueadoSolicitado !== null) {
            $actualizaciones[] = 'esta_bloqueado = :esta_bloqueado';
            $parametros['esta_bloqueado'] = $bloqueadoSolicitado;
        }

        $sql = 'UPDATE chat SET ' . implode(', ', $actualizaciones) . ' WHERE id = :id_chat';
        $stmt = $db->prepare($sql);
        $stmt->execute($parametros);
        $db->commit();

        return responderChat($response, ['mensaje' => 'Chat actualizado correctamente']);
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        return responderChat($response, ['error' => 'No se pudo modificar el chat'], 409);
    }
})->add($authMiddleware);

/** Devuelve mensajes en orden cronológico, seleccionando primero los más recientes. */
$obtenerHistoriaChat = function (Request $request, Response $response, array $args, ?string $quantityArg = null) {
    $logueado = (int) $request->getAttribute('auth_id');
    $userId = filter_var($args['user_id'] ?? null, FILTER_VALIDATE_INT);
    if ($userId === false || $userId < 1) {
        return responderChat($response, ['user_id' => 'Debe ser un ID válido'], 400);
    }

    $quantityRaw = $quantityArg ?? '5';
    if (!preg_match('/^\d+$/', $quantityRaw) || (int) $quantityRaw < 1 || (int) $quantityRaw > 10) {
        return responderChat($response, ['quantity' => 'Debe ser un número entre 1 y 10'], 400);
    }
    $quantity = (int) $quantityRaw;

    $queryParams = $request->getQueryParams();
    $offsetRaw = $queryParams['offset'] ?? '0';
    if (!is_scalar($offsetRaw) || !preg_match('/^\d+$/', (string) $offsetRaw)) {
        return responderChat($response, ['offset' => 'Debe ser un entero mayor o igual a 0'], 400);
    }
    $offset = (int) $offsetRaw;

    try {
        $db = getDB();
        $chat = buscarChatEntreUsuarios($db, $logueado, (int) $userId);
        if (!$chat) {
            return responderChat($response, ['error' => 'No existe un chat entre esos usuarios'], 404);
        }

        $stmt = $db->prepare(
            'SELECT id, texto, created_at AS fecha_creacion, enviado_por
             FROM mensaje
             WHERE chat_id = :chat_id
             ORDER BY created_at DESC, id DESC
             LIMIT ' . $quantity . ' OFFSET ' . $offset
        );
        $stmt->execute(['chat_id' => (int) $chat['id']]);
        $mensajes = $stmt->fetchAll();
        $mensajes = array_reverse($mensajes);
        return responderChat($response, ['mensajes' => $mensajes]);
    } catch (PDOException $e) {
        return responderChat($response, ['error' => 'No se pudo obtener la historia del chat'], 409);
    }
};

// Ruta solicitada con cantidad explícita y variante sin cantidad (por defecto, 5 mensajes).
$app->get('/chats/{user_id}/historia', function (Request $request, Response $response, array $args) use ($obtenerHistoriaChat) {
    return $obtenerHistoriaChat($request, $response, $args);
})->add($authMiddleware);

$app->get('/chats/{user_id}/historia/{quantity}', function (Request $request, Response $response, array $args) use ($obtenerHistoriaChat) {
    return $obtenerHistoriaChat($request, $response, $args, (string) $args['quantity']);
})->add($authMiddleware);
