<?php

declare(strict_types=1);

namespace Upload\Upload;

final class UploadValidationException extends \InvalidArgumentException
{
    public function __construct(
        public readonly string $field,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
