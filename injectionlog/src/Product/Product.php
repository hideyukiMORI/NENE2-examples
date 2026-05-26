<?php

declare(strict_types=1);

namespace Injection\Product;

final readonly class Product
{
    public function __construct(
        public int $id,
        public string $name,
        public string $category,
        public float $price,
        public string $description,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'category'    => $this->category,
            'price'       => $this->price,
            'description' => $this->description,
        ];
    }
}
