<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

require_once __DIR__ . '/db.php';

$authMiddleware = function (Request $request, RequestHandler $handler): Response {
    $authHeader = $request->getHeaderLine('Authorization');
    $respuesta = new \Slim\Psr7\Response();

    if($authHeader === '') {
        $respuesta->getBody()->write(json_encode(["error" => "Token requerido"], JSON_UNESCAPED_UNICODE));
        return $respuesta->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    $token = trim(str_replace('Bearer', '', $authHeader));

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, is_admin, token_expired_at FROM usuario WHERE token = :token");
        $stmt->execute(['token' => $token]);
        $usuario = $stmt->fetch();
    } catch(PDOException $e) {
        $respuesta->getBody()->write(json_encode(["error" => "Error de conexion"], JSON_UNESCAPED_UNICODE));
        return $respuesta->withStatus(409)->withHeader('Content-Type', 'application/json');
    }

    if(!$usuario) {
        $respuesta->getBody()->write(json_encode(["error" => "Token invalido"], JSON_UNESCAPED_UNICODE));
        return $respuesta->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    if( strtotime($usuario['token_expired_at']) < time() ) {
        $respuesta->getBody()->write(json_encode(["error" => "Token expirado"], JSON_UNESCAPED_UNICODE));
        return $respuesta->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    $vence = date('Y-m-d H:i:s', strtotime('+5 minutes'));
    try {
        $stmt = $db->prepare("UPDATE usuario SET token_expired_at = :vence WHERE id = :id");
        $stmt->execute([
            'vence' => $vence,
            'id' => $usuario['id']
        ]);

    } catch(PDOException $e) {
        $respuesta->getBody()->write(json_encode(["error" => "Error de conexion"], JSON_UNESCAPED_UNICODE));
        return $respuesta->withStatus(409)->withHeader('Content-Type', 'application/json');
    }

    $request = $request->withAttribute('auth_id', $usuario['id']);
    $request = $request->withAttribute('auth_admin', $usuario['is_admin']);
    return $handler->handle($request);   // deja pasar al endpoint
};
