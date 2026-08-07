<?php

namespace App\Enums;

/**
 * The upload dropdown (blueprint §J) and file registry (§T).
 *
 * The user picks one of these FIRST, then uploads; the file is then validated
 * against this type's fingerprint before anything is imported. No auto-guessing.
 *
 * The fingerprints themselves (sheet names, expected headers) live in the M2
 * validation layer; this enum is the stable list of choices and their metadata.
 */
enum UploadType: string
{
    case AmazonPoBulk = 'amazon_po_bulk';
    case AmazonPoSingle = 'amazon_po_single';
    case AmazonInterimPacking = 'amazon_interim_packing';
    case AmazonFinalPacking = 'amazon_final_packing';
    case AmazonCancellations = 'amazon_cancellations';
    case AmazonSellout = 'amazon_sellout';
    case AmazonInventory = 'amazon_inventory';
    case AmazonDfs = 'amazon_dfs';
    case AmazonDfsInventory = 'amazon_dfs_inventory';
    case NoonPo = 'noon_po';
    case NoonInterimPicking = 'noon_interim_picking';
    case NoonFinalPicking = 'noon_final_picking';
    case NoonSellout = 'noon_sellout';
    case MasterSheet = 'master_sheet';

    public function label(): string
    {
        return match ($this) {
            self::AmazonPoBulk => 'Amazon — Purchase Order (bulk export)',
            self::AmazonPoSingle => 'Amazon — Purchase Order (single PO)',
            self::AmazonInterimPacking => 'Amazon — Interim Packing List',
            self::AmazonFinalPacking => 'Amazon — Final Packing List',
            self::AmazonCancellations => 'Amazon — Cancellations',
            self::AmazonSellout => 'Amazon — Sell-out report (Sales by ASIN)',
            self::AmazonInventory => 'Amazon — Inventory report (Inventory by ASIN)',
            self::AmazonDfs => 'Amazon — DFS sell-out (orders)',
            self::AmazonDfsInventory => 'Amazon — DFS inventory (bulk CSV)',
            self::NoonPo => 'Noon — Purchase Order',
            self::NoonInterimPicking => 'Noon — Interim Picking List',
            self::NoonFinalPicking => 'Noon — Final Picking List',
            self::NoonSellout => 'Noon — Sell-out & Stock on Hand',
            self::MasterSheet => 'Master Products Sheet',
        };
    }

    public function marketplace(): ?Marketplace
    {
        return match ($this) {
            self::NoonPo, self::NoonInterimPicking, self::NoonFinalPicking,
            self::NoonSellout => Marketplace::Noon,
            self::MasterSheet => null, // cross-marketplace catalog
            default => Marketplace::Amazon,
        };
    }

    public function channel(): ?Channel
    {
        return match ($this) {
            self::AmazonDfs, self::AmazonDfsInventory => Channel::AmazonDfs,
            self::NoonPo, self::NoonInterimPicking, self::NoonFinalPicking,
            self::NoonSellout => Channel::NoonRetail,
            self::MasterSheet => null,
            default => Channel::AmazonRetail,
        };
    }

    /** For packing/picking lists: which stage of the delivery does this file represent? */
    public function stage(): ?Stage
    {
        return match ($this) {
            self::AmazonInterimPacking, self::NoonInterimPicking => Stage::Interim,
            self::AmazonFinalPacking, self::NoonFinalPicking => Stage::Final,
            default => null,
        };
    }

    /** Which permission a user needs to upload this type (§O, §T). */
    public function permission(): string
    {
        return match ($this) {
            self::AmazonPoBulk, self::AmazonPoSingle => 'upload-po',
            self::AmazonInterimPacking, self::AmazonFinalPacking => 'upload-packing-list',
            self::AmazonCancellations => 'upload-cancelled-items',
            self::AmazonSellout => 'upload-sellout',
            self::AmazonDfs => 'upload-dfs',
            // Stock is its own right: it is the file that says what we are sitting on,
            // and it arrives from a different place to the sales feeds (§P, §R, M9).
            self::AmazonInventory, self::AmazonDfsInventory => 'upload-inventory',
            self::NoonPo => 'upload-noon-po',
            self::NoonInterimPicking, self::NoonFinalPicking => 'upload-noon-picking-list',
            self::NoonSellout => 'upload-noon-sellout',
            self::MasterSheet => 'upload-master-sku',
        };
    }

    /**
     * Expected upload cadence in days, or null for event-driven files.
     *
     * Drives the §J freshness nudge banner. POs arrive Mon/Wed + ad-hoc, so they are
     * deliberately left null to avoid noise; DFS is confirmed weekly (§R).
     */
    public function expectedCadenceDays(): ?int
    {
        return match ($this) {
            self::AmazonDfs => 7,
            self::AmazonSellout => 7,
            self::NoonSellout => 7,
            /*
             * Stock goes stale faster than sales do. A sell-out report a week old is
             * history and still true; a stock figure a week old is a wrong answer to
             * "can we cover the next order", which is the question it exists to answer.
             */
            self::AmazonInventory, self::AmazonDfsInventory => 7,
            default => null,
        };
    }

    /** @return array<string,string> value => label, for the dropdown. */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $c) => [$c->value => $c->label()])
            ->all();
    }
}
