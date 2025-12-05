<?php

namespace MiPaymentChoice\Cashier\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use MiPaymentChoice\Cashier\Exceptions\ApiException;

class ApiClient
{
    /**
     * The HTTP client instance.
     *
     * @var \GuzzleHttp\Client
     */
    protected $client;

    /**
     * The API username.
     *
     * @var string
     */
    protected $username;

    /**
     * The API password.
     *
     * @var string
     */
    protected $password;

    /**
     * The base URL for the API.
     *
     * @var string
     */
    protected $baseUrl;

    /**
     * Create a new API client instance.
     *
     * @param  string  $username
     * @param  string  $password
     * @param  string  $baseUrl
     * @return void
     */
    public function __construct($username, $password, $baseUrl)
    {
        $this->username = $username;
        $this->password = $password;
        $this->baseUrl = rtrim($baseUrl, '/');
        
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => 30,
        ]);
    }

    /**
     * Get or refresh the bearer token.
     *
     * @return string
     * @throws ApiException
     */
    protected function getBearerToken()
    {
        return Cache::remember('mipaymentchoice_bearer_token', 3600, function () {
            try {
                $response = $this->client->request('POST', '/api/authenticate', [
                    'json' => [
                        'Username' => $this->username,
                        'Password' => $this->password,
                    ],
                ]);

                $data = json_decode($response->getBody()->getContents(), true);

                if (isset($data['BearerToken'])) {
                    return $data['BearerToken'];
                }

                throw new ApiException('Failed to retrieve bearer token');
            } catch (GuzzleException $e) {
                throw new ApiException('Authentication failed: ' . $e->getMessage());
            }
        });
    }

    /**
     * Make a POST request to the API.
     *
     * @param  string  $endpoint
     * @param  array  $data
     * @return array
     * @throws ApiException
     */
    public function post($endpoint, array $data = [])
    {
        return $this->request('POST', $endpoint, $data);
    }

    /**
     * Make a GET request to the API.
     *
     * @param  string  $endpoint
     * @param  array  $query
     * @return array
     * @throws ApiException
     */
    public function get($endpoint, array $query = [])
    {
        return $this->request('GET', $endpoint, [], $query);
    }

    /**
     * Make a PUT request to the API.
     *
     * @param  string  $endpoint
     * @param  array  $data
     * @return array
     * @throws ApiException
     */
    public function put($endpoint, array $data = [])
    {
        return $this->request('PUT', $endpoint, $data);
    }

    /**
     * Make a PATCH request to the API.
     *
     * @param  string  $endpoint
     * @param  array  $data
     * @return array
     * @throws ApiException
     */
    public function patch($endpoint, array $data = [])
    {
        return $this->request('PATCH', $endpoint, $data);
    }

    /**
     * Make a DELETE request to the API.
     *
     * @param  string  $endpoint
     * @return array
     * @throws ApiException
     */
    public function delete($endpoint)
    {
        return $this->request('DELETE', $endpoint);
    }

    /**
     * Make a request to the API.
     *
     * @param  string  $method
     * @param  string  $endpoint
     * @param  array  $data
     * @param  array  $query
     * @return array
     * @throws ApiException
     */
    protected function request($method, $endpoint, array $data = [], array $query = [])
    {
        try {
            $token = $this->getBearerToken();

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
            ];

            if (!empty($data)) {
                $options['json'] = $data;
            }

            if (!empty($query)) {
                $options['query'] = $query;
            }

            $response = $this->client->request($method, $endpoint, $options);
            $body = $response->getBody()->getContents();

            return json_decode($body, true) ?? [];
        } catch (GuzzleException $e) {
            $message = $e->getMessage();
            $response = [];

            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                $body = $e->getResponse()->getBody()->getContents();
                $response = json_decode($body, true) ?? [];
                
                if (isset($response['ResponseStatus']['Message'])) {
                    $message = $response['ResponseStatus']['Message'];
                }
            }

            throw new ApiException($message, $response);
        }
    }
}
