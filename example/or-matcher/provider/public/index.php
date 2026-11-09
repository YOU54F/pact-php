<?php

use React\Http\Message\Response;
use Psr\Http\Message\ServerRequestInterface;

require __DIR__ . '/../autoload.php';

$app = new FrameworkX\App();

// The consumer test only used the example values ("Fred" and an address object).
// This provider returns the other alternative (null), which is allowed by the OR matching rules.
$app->put('/users/{id}', function () {
    return Response::json([
        'id' => 1,
        'nickname' => null,
        'address' => null,
    ]);
});

$app->post('/pact-change-state', function (ServerRequestInterface $request) {
    /** @var array{action: string, state: string, params: array<array-key, mixed>} $body */
    $body = json_decode((string) $request->getBody(), true);

    return Response::plaintext(sprintf('%s provider state %s with params: %s', $body['action'], $body['state'], (string) json_encode($body['params'])));
});

$app->run();
