<?php

use Psr\Http\Message\ServerRequestInterface as Request;

// Busca el token en el header Authorization (formato "Bearer <token>")
// y devuelve los datos del usuario logueado, o null si no es válido/expiró.
function getAuthenticatedUser(Request $request): ?array
{
    $authHeader = $request->getHeaderLine('Authorization');
    $token = trim(str_replace('Bearer', '', $authHeader));

    if (empty($token)) {
        return null;
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT id, is_admin, token_expired_at FROM usuario WHERE token = :token");
    $stmt->execute(['token' => $token]);
    $user = $stmt->fetch();

    if (!$user) {
        return null;
    }

    if ($user['token_expired_at'] !== null && strtotime($user['token_expired_at']) < time()) {
        return null; // token expirado
    }

    return $user;
}
