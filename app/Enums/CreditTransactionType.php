<?php

namespace App\Enums;

enum CreditTransactionType: string
{
    /** Credits bought by the user (a fake purchase). */
    case Purchase = 'purchase';

    /** Credits given away, e.g. the welcome credit on registration. */
    case Grant = 'grant';

    /** The quoted maximum held when a generation is submitted. */
    case Reservation = 'reservation';

    /** The hold returned once a generation reaches a final state. */
    case ReservationRelease = 'reservation_release';

    /** What a succeeded generation actually cost the user. */
    case Charge = 'charge';
}
