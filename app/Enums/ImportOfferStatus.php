<?php

namespace App\Enums;

enum ImportOfferStatus: string
{
    case Pending = 'pending';
    case Applied = 'applied';
    case Skipped = 'skipped';
}
