<?php

declare(strict_types=1);

namespace SwagUcp\Service;

class SchemaLoaderService
{
    public function __construct(
        private readonly string $schemaPath,
    ) {
    }

    public function loadSchema(string $schemaName, array $capabilities = []): array
    {
        $schemaFile = $this->schemaPath . '/' . $schemaName . '.json';

        if (!file_exists($schemaFile)) {
            throw new \RuntimeException("Schema file not found: {$schemaFile}");
        }

        $schema = json_decode(file_get_contents($schemaFile), true);
        if (json_last_error() !== \JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON in schema file: {$schemaFile}");
        }

        // Resolve $ref references and compose with extensions
        return $this->resolveSchema($schema, $capabilities);
    }

    private function resolveSchema(array $schema, array $capabilities): array
    {
        // In a full implementation, this would:
        // 1. Resolve $ref references
        // 2. Compose with extension schemas based on active capabilities
        // 3. Handle allOf composition

        return $schema;
    }
}
