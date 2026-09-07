<?php

namespace App\Enums;

use Illuminate\Database\QueryException;
use Throwable;

enum ImportOfferError: string
{
    case InvalidData = 'invalid_data';

    case ConstraintViolation = 'constraint_violation';

    public static function for(Throwable $e): ?self
    {
        if (! $e instanceof QueryException) {
            return null;
        }

        return match (substr((string) $e->getCode(), 0, 2)) {
            '22' => self::InvalidData,
            '23' => self::ConstraintViolation,
            default => null,
        };
    }
}
