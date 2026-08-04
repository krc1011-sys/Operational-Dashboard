<?php

namespace App\Enums;

/**
 * The two marketplaces we ingest from. This is the IDENTITY namespace: an ASIN is
 * unique within Amazon, a NIN is unique within Noon, and the two never collide.
 *
 * Not to be confused with Channel, which is the REPORTING dimension (Amazon Retail
 * and Amazon DFS are both marketplace=amazon but are different channels).
 */
enum Marketplace: string
{
    case Amazon = 'amazon';
    case Noon = 'noon';

    public function label(): string
    {
        return match ($this) {
            self::Amazon => 'Amazon',
            self::Noon => 'Noon',
        };
    }

    /** The native product identifier this marketplace joins on (blueprint §B, §Q). */
    public function skuIdType(): string
    {
        return match ($this) {
            self::Amazon => 'asin',
            self::Noon => 'nin',
        };
    }
}
