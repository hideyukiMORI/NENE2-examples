<?php

declare(strict_types=1);

namespace PatchLog\Document;

enum DocumentStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
