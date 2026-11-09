<?php

namespace OrMatcherConsumer\Service;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;

class HttpClientService
{
    private Client $httpClient;

    public function __construct(private string $baseUri)
    {
        $this->httpClient = new Client();
    }

    /**
     * @param array<string, mixed> $user
     */
    public function updateUser(array $user, string $limit, string $apiVersion): ResponseInterface
    {
        return $this->httpClient->put("{$this->baseUri}/users/1", [
            'headers' => ['Accept' => 'application/json', 'X-Api-Version' => $apiVersion],
            'query' => ['limit' => $limit],
            'json' => $user,
            'http_errors' => false,
        ]);
    }
}
