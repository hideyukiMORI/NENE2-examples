<?php

declare(strict_types=1);

namespace SortLog\Article;

enum ArticleStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
