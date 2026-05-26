<?php

declare(strict_types=1);

namespace ReportLog\Report;

enum ReportReason: string
{
    case Spam = 'spam';
    case Harassment = 'harassment';
    case Misinformation = 'misinformation';
    case Other = 'other';
}
