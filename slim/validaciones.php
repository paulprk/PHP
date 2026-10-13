<?php

function validarUsername($u)
{
    if(!is_string($u)) {
        return "Debe ser un texto";
    }
    if(strlen($u) < 5 || strlen($u) > 12) {
        return "Debe tener entre 5 y 12 caracteres";
    }

    if(substr($u, 0, 1) !== '@') {
        return "Debe empezar con @";
    }

    if(!preg_match("/^[a-zA-Z0-9]+$/",substr($u,1))) {
        return "Solo letras y numeros";
    }

    return null;
}

function validarPassword($p)
{
    if(!is_string($p)) {
        return "Debe ser un texto";
    }
    if(mb_strlen($p) < 8 || mb_strlen($p) > 15) {
        return "Debe contener entre 8 y 15 caracteres";
    }
    if(!preg_match("/[a-z]/",$p)) {
        return "Al menos 1 minuscula";
    }
    if(!preg_match("/[A-Z]/",$p)) {
        return "Al menos 1 mayuscula";
    }
    if(!preg_match("/\d/",$p)) {
        return "Al menos 1 numero";
    }
    if(!preg_match("/[^a-zA-Z0-9]/",$p)) {
        return "Al menos 1 caracter especial";
    }
    return null;
}

function validarNombre($n)
{
    if(!is_string($n)) {
        return "Debe ser un texto";
    }
    if(trim($n) === '') {
        return "El nombre no puede estar vacio";
    }
    if(mb_strlen($n) < 2 || mb_strlen($n) > 50) {
        return "Debe contener entre 2 y 50 caracteres";
    }
    if(!preg_match("/^[\p{L} ]+$/u",$n)) {
        return "Solo letras y espacios";
    }
    return null;
}

function validarRegistro($datos)
{
    $errores = [];
    $error = validarUsername($datos['username'] ?? '');
    if($error !== null) {
        $errores['username'] = $error;
    }

    $error = validarPassword($datos['password'] ?? '');
    if($error !== null) {
        $errores['password'] = $error;
    }

    $error = validarNombre($datos['nombre'] ?? '');
    if($error !== null) {
        $errores['nombre'] = $error;
    }
    return $errores;
}

function validarLogin($data)
{
    $errores = [];
    $u = $data['username'] ?? '';
    $p = $data['password'] ?? '';
    if (!is_string($u) || trim($u) === '') {
        $errores['username'] = 'El username es obligatorio y debe ser texto';
    }
    if (!is_string($p) || trim($p) === '') {
        $errores['password'] = 'La contraseña es obligatoria y debe ser texto';
    }
    return $errores;
}

function puedeAccederAUsurio($userId,$request) 
{
    $logueado = (int) $request->getAttribute('auth_id');
    $esAdmin = (int) $request->getAttribute('auth_admin') === 1;
    return $userId === $logueado || $esAdmin;
}
