<?php

declare(strict_types=1);

namespace Nested\Order;

/**
 * Validates a nested order payload and accumulates all errors with structured paths.
 *
 * Error path convention: dot-notation with 0-based index for array elements.
 * Examples: "customer", "items", "items.0.quantity", "items.2.unit_price"
 *
 * Returns all errors at once (not fail-fast) so the client can fix everything in one round-trip.
 */
final class OrderValidator
{
    /**
     * @param mixed $body
     * @return array{
     *   errors: list<array{field: string, code: string, message: string}>,
     *   customer: string,
     *   note: string,
     *   items: list<array{product_id: int, quantity: int, unit_price: float}>
     * }|array{errors: list<array{field: string, code: string, message: string}>}
     */
    public function validate(mixed $body): array
    {
        if (!is_array($body)) {
            return ['errors' => [['field' => '', 'code' => 'invalid-body', 'message' => 'Request body must be a JSON object.']]];
        }

        $errors = [];

        // --- top-level fields ---
        $customer = isset($body['customer']) && is_string($body['customer']) ? trim($body['customer']) : '';
        if ($customer === '') {
            $errors[] = ['field' => 'customer', 'code' => 'required', 'message' => 'Customer name is required.'];
        } elseif (strlen($customer) > 200) {
            $errors[] = ['field' => 'customer', 'code' => 'too-long', 'message' => 'Customer name must not exceed 200 characters.'];
        }

        $note = isset($body['note']) && is_string($body['note']) ? trim($body['note']) : '';

        // --- items array ---
        if (!isset($body['items'])) {
            $errors[] = ['field' => 'items', 'code' => 'required', 'message' => 'items is required.'];
        } elseif (!is_array($body['items']) || array_is_list($body['items']) === false) {
            $errors[] = ['field' => 'items', 'code' => 'must-be-array', 'message' => 'items must be an array.'];
        } elseif (count($body['items']) === 0) {
            $errors[] = ['field' => 'items', 'code' => 'min-items', 'message' => 'Order must contain at least one item.'];
        } elseif (count($body['items']) > 50) {
            $errors[] = ['field' => 'items', 'code' => 'max-items', 'message' => 'Order may not contain more than 50 items.'];
        } else {
            foreach ($body['items'] as $i => $item) {
                $prefix = "items.{$i}";

                if (!is_array($item)) {
                    $errors[] = ['field' => $prefix, 'code' => 'must-be-object', 'message' => "items.{$i} must be an object."];
                    continue;
                }

                // product_id
                if (!isset($item['product_id']) || !is_int($item['product_id'])) {
                    $errors[] = ['field' => "{$prefix}.product_id", 'code' => 'required', 'message' => "{$prefix}.product_id must be an integer."];
                } elseif ($item['product_id'] < 1) {
                    $errors[] = ['field' => "{$prefix}.product_id", 'code' => 'min-value', 'message' => "{$prefix}.product_id must be ≥ 1."];
                }

                // quantity
                if (!isset($item['quantity']) || !is_int($item['quantity'])) {
                    $errors[] = ['field' => "{$prefix}.quantity", 'code' => 'required', 'message' => "{$prefix}.quantity must be an integer."];
                } elseif ($item['quantity'] < 1) {
                    $errors[] = ['field' => "{$prefix}.quantity", 'code' => 'min-value', 'message' => "{$prefix}.quantity must be ≥ 1."];
                } elseif ($item['quantity'] > 9999) {
                    $errors[] = ['field' => "{$prefix}.quantity", 'code' => 'max-value', 'message' => "{$prefix}.quantity must be ≤ 9999."];
                }

                // unit_price — accepts both int and float from JSON
                $rawPrice = $item['unit_price'] ?? null;
                if (!is_int($rawPrice) && !is_float($rawPrice)) {
                    $errors[] = ['field' => "{$prefix}.unit_price", 'code' => 'required', 'message' => "{$prefix}.unit_price must be a number."];
                } elseif ($rawPrice <= 0) {
                    $errors[] = ['field' => "{$prefix}.unit_price", 'code' => 'min-value', 'message' => "{$prefix}.unit_price must be > 0."];
                }
            }
        }

        if ($errors !== []) {
            return ['errors' => $errors];
        }

        // Build typed items list — safe to cast since all validations passed
        /** @var list<array{product_id: int, quantity: int, unit_price: float}> */
        $validItems = array_map(
            static fn (array $item) => [
                'product_id' => (int) $item['product_id'],
                'quantity'   => (int) $item['quantity'],
                'unit_price' => (float) ($item['unit_price'] ?? 0),
            ],
            (array) $body['items'],
        );

        return [
            'errors'   => [],
            'customer' => $customer,
            'note'     => $note,
            'items'    => $validItems,
        ];
    }
}
