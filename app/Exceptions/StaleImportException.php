<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class StaleImportException extends HttpException
{
    public function __construct()
    {
        parent::__construct(
            statusCode: 409,
            message: 'The import is not newer than the latest import received from this supplier.',
        );
    }
}
