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
    case AmazonDfs = 'amazon_dfs';
    case NoonPo = 'noon_po';
    case NoonInterimPicking = 'noon_interim_picking';
    case NoonFinalPicking = 'noon_final_picking';
    case MasterSheet = 'master_sheet';

    public function label(): string
    {
        return match ($this) {
            self::AmazonPoBulk => 'Amazon — Purchase Order (bulk export)',
            self::AmazonPoSingle => 'Amazon — Purchase Order (single PO)',
            self::AmazonInterimPacking => 'Amazon — Interim Packing List',
            self::AmazonFinalPacking => 'Amazon — Final Packing List',
            self::AmazonCancellations => 'Amazon — Cancellations',
            self::AmazonSellout => 'Amazon — Sell-out report',
            self::AmazonDfs => 'Amazon — DFS orders',
            self::NoonPo => 'Noon — Purchase Order',
            self::NoonInterimPicking => 'Noon — Interim Picking List',
            self::NoonFinalPicking => 'Noon — Final Picking List',
            self::MasterSheet => 'Master Products Sheet',
        };
    }

    public function marketplace(): ?Marketplace
    {
        return match ($this) {
            self::NoonPo, self::NoonInterimPicking, self::NoonFinalPicking => Marketplace::Noon,
            self::MasterSheet => null, // cross-marketplace catalog
            default => Marketplace::Amazon,
        };
    }

    public function channel(): ?Channel
    {
        return match ($this) {
            self::AmazonDfs => Channel::AmazonDfs,
            self::NoonPo, self::NoonInterimPicking, self::NoonFinalPicking => Channel::NoonRetail,
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
            self::NoonPo => 'upload-noon-po',
            self::NoonInterimPicking, self::NoonFinalPicking => 'upload-noon-picking-list',
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
