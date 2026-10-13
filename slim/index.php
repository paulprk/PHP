<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/vendor/autoload.php';   //carga slim
//require __DIR__ . '/db.php';


$app = AppFactory::create();        //crea la app
$app->addRoutingMiddleware();
$app->addBodyParsingMiddleware();   //hace que el JSON que manda el cliente llegue como array en $request->GetParsedBody();
$app->addErrorMiddleware(true, true, true);
$app->add( function ($request, $handler) {
    $response = $handler->handle($request);

    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')    //agrega headers a todas las respuestas
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'OPTIONS, GET, POST, PUT, PATCH, DELETE')
        ->withHeader('Content-Type', 'application/json')
    ;
});

// carga endpoints
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/auth.php';

$app->run();