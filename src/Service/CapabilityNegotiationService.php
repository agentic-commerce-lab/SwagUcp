<?php

declare(strict_types=1);

namespace SwagUcp\Service;

class CapabilityNegotiationService
{
    /**
     * Negotiate capabilities between platform and business.
     *
     * @param array<string, mixed> $platformProfile
     * @param list<array<string, mixed>> $businessCapabilities
     *
     * @return list<array<string, mixed>>
     */
    public function negotiate(array $platformProfile, array $businessCapabilities): array
    {
        /** @var list<array<string, mixed>> $platformCapabilities */
        $platformCapabilities = $platformProfile['ucp']['capabilities'] ?? [];

        // 1. Compute intersection
        /** @var list<array<string, mixed>> $intersection */
        $intersection = [];
        foreach ($businessCapabilities as $businessCap) {
            foreach ($platformCapabilities as $platformCap) {
                if (($businessCap['name'] ?? '') === ($platformCap['name'] ?? '')) {
                    // Version check: platform version must be <= business version
                    $platformVersion = \is_string($platformCap['version'] ?? null) ? $platformCap['version'] : '2026-01-11';
                    $businessVersion = \is_string($businessCap['version'] ?? null) ? $businessCap['version'] : '2026-01-11';

                    if ($this->isVersionCompatible($platformVersion, $businessVersion)) {
                        $negotiated = [
                            'name' => $businessCap['name'],
                            'version' => $businessVersion,
                        ];

                        // Preserve 'extends' field if present for pruning logic
                        if (isset($businessCap['extends']) && \is_string($businessCap['extends'])) {
                            $negotiated['extends'] = $businessCap['extends'];
                        }

                        $intersection[] = $negotiated;
                        break;
                    }
                }
            }
        }

        // 2. Prune orphaned extensions
        $activeNames = array_column($intersection, 'name');
        /** @var list<array<string, mixed>> $filtered */
        $filtered = [];

        foreach ($intersection as $cap) {
            $extends = $cap['extends'] ?? null;
            if ($extends === null || \in_array($extends, $activeNames, true)) {
                $filtered[] = $cap;
            }
        }

        // 3. Repeat pruning until stable (handles transitive extensions)
        $previousCount = 0;
        while (\count($filtered) !== $previousCount) {
            $previousCount = \count($filtered);
            $activeNames = array_column($filtered, 'name');
            $filtered = array_values(array_filter($filtered, static function (array $cap) use ($activeNames): bool {
                $extends = $cap['extends'] ?? null;

                return $extends === null || \in_array($extends, $activeNames, true);
            }));
        }

        return $filtered;
    }

    private function isVersionCompatible(string $platformVersion, string $businessVersion): bool
    {
        // Platform version must be <= business version
        $platformTimestamp = strtotime($platformVersion);
        $businessTimestamp = strtotime($businessVersion);

        return $platformTimestamp !== false
            && $businessTimestamp !== false
            && $platformTimestamp <= $businessTimestamp;
    }
}
