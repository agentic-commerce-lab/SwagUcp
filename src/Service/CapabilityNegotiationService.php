<?php

declare(strict_types=1);

namespace SwagUcp\Service;

class CapabilityNegotiationService
{
    public function negotiate(array $platformProfile, array $businessCapabilities): array
    {
        $platformCapabilities = $platformProfile['ucp']['capabilities'] ?? [];
        
        // 1. Compute intersection
        $intersection = [];
        foreach ($businessCapabilities as $businessCap) {
            foreach ($platformCapabilities as $platformCap) {
                if ($businessCap['name'] === $platformCap['name']) {
                    // Version check: platform version must be <= business version
                    if ($this->isVersionCompatible($platformCap['version'] ?? '2026-01-11', $businessCap['version'])) {
                        $intersection[] = [
                            'name' => $businessCap['name'],
                            'version' => $businessCap['version']
                        ];
                        break;
                    }
                }
            }
        }

        // 2. Prune orphaned extensions
        $activeNames = array_column($intersection, 'name');
        $filtered = [];
        
        foreach ($intersection as $cap) {
            if (!isset($cap['extends']) || in_array($cap['extends'], $activeNames)) {
                $filtered[] = $cap;
            }
        }

        // 3. Repeat pruning until stable (handles transitive extensions)
        $previousCount = 0;
        while (count($filtered) !== $previousCount) {
            $previousCount = count($filtered);
            $activeNames = array_column($filtered, 'name');
            $filtered = array_filter($filtered, function($cap) use ($activeNames) {
                return !isset($cap['extends']) || in_array($cap['extends'], $activeNames);
            });
            $filtered = array_values($filtered);
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
