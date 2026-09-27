<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require_once __DIR__ . '/../db.php';

//datos son: username, nombre, password
$app->post('/usuarios', function (Request $request, Response $response, array $args) {
    $data = $request->getParsedBody();      

    //verifica q no esten los campos vacios
    if(empty($data['username']) || empty($data['password']) || empty($data['nombre'])) {
        $response->getBody()->write(json_encode(["error" => "Faltan datos obligatorios (username, password o nombre)"]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    //verifica que el username sea valido, indicando puntualmente que le falta
    $username = $data['username'];
    $erroresUsername = [];

    if (substr($username, 0, 1) !== '@') {
        $erroresUsername[] = "debe empezar con @";
    }

    $restoSinArroba = (substr($username, 0, 1) === '@') ? substr($username, 1) : $username;
    if (!preg_match("/^[a-zA-Z0-9]*$/", $restoSinArroba)) {
        $erroresUsername[] = "no puede contener caracteres especiales (solo letras y números)";
    }

    if (strlen($username) < 5 || strlen($username) > 12) {
        $erroresUsername[] = "longitud entre 5 y 12 caracteres (incluyendo el @)";
    }

    if (!empty($erroresUsername)) {
        $response->getBody()->write(json_encode([
            "error" => "Username inválido, falta: " . implode(', ', $erroresUsername),
        ]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    //verifica que la contraseña sea valida, indicando puntualmente que le falta
    $password = $data['password'];
    $erroresPassword = [];

    if (!preg_match("/[a-z]/", $password)) {
        $erroresPassword[] = "al menos 1 minúscula";
    }
    if (!preg_match("/[A-Z]/", $password)) {
        $erroresPassword[] = "al menos 1 mayúscula";
    }
    if (!preg_match("/\d/", $password)) {
        $erroresPassword[] = "al menos 1 número";
    }
    if (!preg_match("/[\W_]/", $password)) {
        $erroresPassword[] = "al menos 1 carácter especial";
    }
    if (strlen($password) < 8 || strlen($password) > 15) {
        $erroresPassword[] = "longitud entre 8 y 15 caracteres";
    }

    if (!empty($erroresPassword)) {
        $response->getBody()->write(json_encode([
            "error" => "Contraseña inválida, falta: " . implode(', ', $erroresPassword),
        ]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    //verifica que el nombre sea valido
    if (!preg_match("/^[a-zA-ZáéíóúÁÉÍÓÚñÑ ]{2,50}$/", $data['nombre'])) {
        $response->getBody()->write(json_encode(["error" => "Nombre invalido"]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    try {
        $db = getDB();

        // verifica que el username sea unico
        $stmt = $db->prepare("SELECT id FROM usuario WHERE username = :username");
        $stmt->execute(['username' => $data['username']]);
        if ($stmt->fetch()) {
            $response->getBody()->write(json_encode(["error" => "El username ya existe"]));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        }

        // hashea la contraseña antes de guardarla (nunca se guarda en texto plano)
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $db->prepare(
            "INSERT INTO usuario (username, password, nombre, es_publico) VALUES (:username, :password, :nombre, 0)"
        );
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

        $response->getBody()->write(json_encode(["error" => "Error al crear el usuario"]));
        return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
    }

});

/*$app->get('/usuarios/{user_id}', function (Request $request, Response $response, array $args) {
    $authUser = getAuthenticatedUser($request);

    if (!$authUser) {
        $response->getBody()->write(json_encode(["error" => "No autenticado"]));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    $userId = (int) $args['user_id'];

    // solo puede ver su propio perfil, salvo que sea admin
    if ((int) $authUser['id'] !== $userId && !$authUser['is_admin']) {
        $response->getBody()->write(json_encode(["error" => "No autorizado"]));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT username, nombre, es_publico FROM usuario WHERE id = :id");
    $stmt->execute(['id' => $userId]);
    $perfil = $stmt->fetch();

    if (!$perfil) {
        $response->getBody()->write(json_encode(["error" => "Usuario no encontrado"]));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    $response->getBody()->write(json_encode($perfil));
    return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
});*/



