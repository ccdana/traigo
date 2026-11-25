<?php

namespace App\Enums;

/** Igual que el tipo `payment_status` de la base. */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Submitted = 'submitted';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Reversed = 'reversed';
}
