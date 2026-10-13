<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

/** @var \Slim\App $app */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/validaciones.php';
require_once __DIR__ . '/middleware.php';

//datos son: username, nombre, password
$app->post('/usuarios', function (Request $request, Response $response) {
    $data = $request->getParsedBody();

    $errores = validarRegistro($data ?? []);

    if (!empty($errores)) {
        $response->getBody()->write(json_encode($errores, JSON_UNESCAPED_UNICODE));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    $password = $data['password'];

    try {
        $db = getDB();

        // verifica que el username sea unico
        $stmt = $db->prepare("SELECT id FROM usuario WHERE username = :username");
        $stmt->execute(['username' => $data['username']]);
        if ($stmt->fetch()) {
            $response->getBody()->write(json_encode(["username" => "El username ya existe"], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        }

        // hashea la contraseña antes de guardarla (nunca se guarda en texto plano)
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO usuario (username, password, nombre, es_publico) VALUES 
        (:username, :password, :nombre, 0)");
        $stmt->execute([
            'username' => $data['username'],
            'password' => $passwordHash,
            'nombre' => $data['nombre'],
        ]);

        $response->getBody()->write(json_encode([
            "message" => "Usuario creado correctamente",
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');

    } catch (PDOException $e) {
        $response->getBody()->write(json_encode(["error" => "Error al crear el usuario"], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(409)->withHeader('Content-Type', 'application/json'); //consultar fallo 409 o 500
    }

});

$app->get('/usuarios/{user_id}', function (Request $request, Response $response,array $args) {
    $userId = (int) $args['user_id'];

    if(!puedeAccederAUsurio($userId,$request)) {
        $response->getBody()->write(json_encode(["error" => "No es el usuario, o no es Admin"], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT username, nombre, es_publico FROM usuario WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $perfil = $stmt->fetch();

        if(!$perfil) {
            $response->getBody()->write(json_encode(["error" => "Usuario no encontrado"], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode($perfil, JSON_UNESCAPED_UNICODE));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');

    } catch(PDOException $e) {
        $response->getBody()->write(json_encode(["error" => "Error de conexion"]));
        return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
    }
})->add($authMiddleware);

$app->put('/usuarios/{user_id}', function (Request $request,Response $response,array $args) {
    $userId = (int) $args['user_id'];

    //validar q sea usuario o admin
    if (!puedeAccederAUsurio($userId, $request)) {
        $response->getBody()->write(json_encode(["error" => "No es el usuario, o no es Admin"], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    $data = $request->getParsedBody() ?? [];

    //validar que no esten vacios los campos
    if (!isset($data['nombre']) && !isset($data['password']) && !isset($data['es_publico'])) {
        $response->getBody()->write(json_encode(["error" => "No hay nada que modificar"], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    //validar datos por dato que coincidan con las reglas de cada uno
    $errores = [];
    if(isset($data['nombre'])) {
        $error = validarNombre($data['nombre']);
        if($error !== null) {
            $errores['nombre'] = $error;
        }
    }
    if(isset($data['password'])) {
        $error = validarPassword($data['password']);
        if($error !== null) {
            $errores['password'] = $error;
        }
    }
    if(isset($data['es_publico'])) {
        if(!in_array($data['es_publico'], [0, 1, '0', '1', true, false], true)) {
            $errores['es_publico'] = "Debe ser 0 o 1";
        }
    }

    //si no esta vacio el array errores imprime todos los errores encontrados
    if(!empty($errores)) {
        $response->getBody()->write(json_encode($errores, JSON_UNESCAPED_UNICODE));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT nombre,password,es_publico FROM usuario WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $actual = $stmt->fetch();

        if(!$actual) {
            $response->getBody()->write(json_encode(["error" => "Usuario no encontrado"], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $nombre = $data['nombre'] ?? $actual['nombre'];
        $password = isset($data['password']) ? password_hash($data['password'], PASSWORD_DEFAULT) : $actual['password'];
        $es_publico = isset($data['es_publico']) ? (int) $data['es_publico'] : $actual['es_publico'];

        $stmt = $db->prepare("UPDATE usuario SET nombre = :nombre, password = :password, es_publico = :es_publico WHERE id = :id");
        $stmt->execute([
            'nombre' => $nombre,
            'password' => $password,
            'es_publico' => $es_publico,
            'id' => $userId,
        ]);

        $response->getBody()->write(json_encode(["mensaje" => "Usuario actualizado"], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');

    } catch(PDOException $e) {
        $response->getBody()->write(json_encode(["error" => "Error de conexion"], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
    }



})->add($authMiddleware);

