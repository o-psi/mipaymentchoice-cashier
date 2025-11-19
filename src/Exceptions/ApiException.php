<?php

namespace MiPaymentChoice\Cashier\Exceptions;

use Exception;

class ApiException extends Exception
{
    /**
     * The response from the API.
     *
     * @var array
     */
    protected $response;

    /**
     * Create a new exception instance.
     *
     * @param  string  $message
     * @param  array  $response
     * @return void
     */
    public function __construct($message = '', array $response = [])
    {
        parent::__construct($message);

        $this->response = $response;
    }

    /**
     * Get the API response.
     *
     * @return array
     */
    public function getResponse()
    {
        return $this->response;
    }
}
