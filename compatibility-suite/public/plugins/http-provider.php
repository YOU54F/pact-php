<?php

// Router for `php -S`: a provider that returns a fixed body, or (when PROVIDER_DESCRIPTION is set) a message
// provider that answers the verifier's POST of {"description": ...} with the message and its metadata.

$body = (string) file_get_contents((string) getenv('PROVIDER_BODY_FILE'));
$contentType = (string) getenv('PROVIDER_CONTENT_TYPE');
$description = getenv('PROVIDER_DESCRIPTION');

if (false !== $description) {
    $request = json_decode((string) file_get_contents('php://input'), true);
    $asked = is_array($request) && is_string($request['description'] ?? null) ? $request['description'] : '';
    if ($asked !== $description) {
        http_response_code(404);
        echo sprintf('no message "%s"', $asked);

        return true;
    }
    header('pact-message-metadata: ' . base64_encode((string) json_encode(['contentType' => $contentType])));
}

http_response_code(200);
header('Content-Type: ' . $contentType);
echo $body;

return true;
