<?php

namespace App\Enums;

/**
 * `Enquiry.type` — which storefront form produced the enquiry.
 */
enum EnquiryType: string
{
    case Contact = 'contact';
    case Quote = 'quote';
    case Fleet = 'fleet';
    case OutOfArea = 'out_of_area';
}
