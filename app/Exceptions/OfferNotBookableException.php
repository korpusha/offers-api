<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class OfferNotBookableException extends HttpException
{
    public function __construct()
    {
        parent::__construct(
            statusCode: 409,
            message: 'The offer is no longer available.',
        );
    }
}
