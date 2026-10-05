<?php

namespace App\Enums;

/**
 * `Enquiry.status` — simple triage workflow for the admin inbox.
 */
enum EnquiryStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Spam = 'spam';
}
