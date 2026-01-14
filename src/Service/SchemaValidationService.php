<?php

declare(strict_types=1);

namespace SwagUcp\Service;

class SchemaValidationService
{
    public function __construct(
        private readonly SchemaLoaderService $schemaLoader,
    ) {
    }

    public function validate(string $operation, array $data, array $capabilities): void
    {
        // Perform basic validation without external library
        $errors = $this->validateBasic($operation, $data, $capabilities);

        if (!empty($errors)) {
            throw new \InvalidArgumentException('Validation failed: ' . json_encode($errors));
        }
    }

    private function validateBasic(string $operation, array $data, array $capabilities): array
    {
        $errors = [];

        switch ($operation) {
            case 'checkout.create':
                // Validate required fields for checkout creation
                if (!isset($data['line_items']) || !\is_array($data['line_items'])) {
                    $errors[] = [
                        'property' => 'line_items',
                        'message' => 'line_items is required and must be an array',
                    ];
                } else {
                    foreach ($data['line_items'] as $index => $lineItem) {
                        if (!isset($lineItem['item'])) {
                            $errors[] = [
                                'property' => "line_items[$index].item",
                                'message' => 'item is required',
                            ];
                        } elseif (!isset($lineItem['item']['id'])) {
                            $errors[] = [
                                'property' => "line_items[$index].item.id",
                                'message' => 'item.id is required',
                            ];
                        }

                        if (!isset($lineItem['quantity']) || !\is_int($lineItem['quantity']) || $lineItem['quantity'] < 1) {
                            $errors[] = [
                                'property' => "line_items[$index].quantity",
                                'message' => 'quantity must be a positive integer',
                            ];
                        }
                    }
                }
                break;

            case 'checkout.update':
                // Update can have partial data, minimal validation
                if (isset($data['line_items']) && !\is_array($data['line_items'])) {
                    $errors[] = [
                        'property' => 'line_items',
                        'message' => 'line_items must be an array',
                    ];
                }

                if (isset($data['buyer']) && !\is_array($data['buyer'])) {
                    $errors[] = [
                        'property' => 'buyer',
                        'message' => 'buyer must be an object',
                    ];
                }

                if (isset($data['buyer']['email']) && !filter_var($data['buyer']['email'], \FILTER_VALIDATE_EMAIL)) {
                    $errors[] = [
                        'property' => 'buyer.email',
                        'message' => 'Invalid email format',
                    ];
                }
                break;
        }

        return $errors;
    }
}
