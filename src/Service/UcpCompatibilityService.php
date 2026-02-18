<?php

declare(strict_types=1);

namespace SwagUcp\Service;

use SwagUcp\Ucp;

class UcpCompatibilityService
{
    /**
     * @param array<string, mixed>         $profile
     * @param list<array<string, string>> $capabilities
     * @param list<array<string, mixed>>  $handlers
     *
     * @return array<string, mixed>
     */
    public function buildProfile(array $profile, string $version, array $capabilities, array $handlers): array
    {
        $versioned = $version === Ucp::VERSION_2026_01_11
            ? $this->buildProfileV2026_01_11($capabilities, $handlers)
            : $this->buildProfileVCurrent($capabilities, $handlers);

        $profile['ucp']['capabilities'] = $versioned['capabilities'];
        $profile['ucp'] = array_merge($profile['ucp'], $versioned['payment_section']);

        return $profile;
    }

    /**
     * @param list<array<string, string>> $capabilities
     * @param list<array<string, mixed>>  $handlers
     *
     * @return array{capabilities: list<array<string, string>>, payment_section: array{payment: array{handlers: list<array<string, mixed>>}}}
     */
    private function buildProfileV2026_01_11(array $capabilities, array $handlers): array
    {
        return [
            'capabilities' => $capabilities,
            'payment_section' => [
                'payment' => [
                    'handlers' => $handlers,
                ],
            ],
        ];
    }

    /**
     * @param list<array<string, string>> $capabilities
     * @param list<array<string, mixed>>  $handlers
     *
     * @return array{capabilities: array<string, array<string, string>>, payment_section: array{payment_handlers: array<string, array<string, mixed>>}}
     */
    private function buildProfileVCurrent(array $capabilities, array $handlers): array
    {
        $handlersMap = $this->toNameMap($handlers);
        $handlersMap = $this->renameHandlerConfigSchemaToSchema($handlersMap);

        return [
            'capabilities' => $this->toNameMap($capabilities),
            'payment_section' => [
                'payment_handlers' => $handlersMap,
            ],
        ];
    }

    /**
     * For current version: expose config_schema as schema in each payment handler.
     *
     * @param array<string, array<string, mixed>> $handlersMap
     *
     * @return array<string, array<string, mixed>>
     */
    private function renameHandlerConfigSchemaToSchema(array $handlersMap): array
    {
        foreach ($handlersMap as $name => $handler) {
            if (\array_key_exists('config_schema', $handler)) {
                $handler['schema'] = $handler['config_schema'];
                unset($handler['config_schema']);
                $handlersMap[$name] = $handler;
            }
        }

        return $handlersMap;
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, array<string, mixed>>
     */
    private function toNameMap(array $items): array
    {
        $map = [];
        foreach ($items as $item) {
            $name = $item['name'] ?? null;
            if ($name !== null) {
                $rest = $item;
                unset($rest['name']);
                $map[$name] = $rest;
            }
        }

        return $map;
    }
}
