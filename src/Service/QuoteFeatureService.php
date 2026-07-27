<?php

declare(strict_types=1);

namespace SwagUcp\Service;

/**
 * Runtime detection of the B2B quote capability.
 *
 * SwagUcp has a soft dependency on SwagCommercial: the quote capability is
 * advertised and its routes enabled only when the commercial plugin is
 * installed AND the Quote Management feature is licensed. Never a composer
 * dependency - all commercial classes are referenced as strings.
 */
class QuoteFeatureService
{
    /** License toggle used by all Commercial quote routes (see QuoteRequestRoute). */
    public const LICENSE_TOGGLE = 'QUOTE_MANAGEMENT-8702512';

    private const QUOTE_MANAGEMENT_CLASS = 'Shopware\Commercial\B2B\QuoteManagement\QuoteManagement';
    private const LICENSE_CLASS = 'Shopware\Commercial\Licensing\License';

    /** @var (callable(): bool)|null */
    private $availabilityCheck;

    /**
     * @param (callable(): bool)|null $availabilityCheck test override for environments
     *                                                   where the commercial static license state cannot be controlled
     */
    public function __construct(?callable $availabilityCheck = null)
    {
        $this->availabilityCheck = $availabilityCheck;
    }

    public function isAvailable(): bool
    {
        if ($this->availabilityCheck !== null) {
            return ($this->availabilityCheck)();
        }

        if (!class_exists(self::QUOTE_MANAGEMENT_CLASS) || !class_exists(self::LICENSE_CLASS)) {
            return false;
        }

        try {
            $license = self::LICENSE_CLASS;

            return $license::get(self::LICENSE_TOGGLE) !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}
