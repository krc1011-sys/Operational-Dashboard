<?php

namespace App\Console\Commands;

use App\Enums\Marketplace;
use App\Enums\SourceFileStatus;
use App\Enums\Stage;
use App\Enums\UploadType;
use App\Models\Cancellation;
use App\Models\Delivery;
use App\Models\MasterAnomaly;
use App\Models\PoLine;
use App\Models\Product;
use App\Models\ProductChannelEconomics;
use App\Models\ProductIdentifier;
use App\Models\PurchaseOrder;
use App\Models\ShipmentLine;
use App\Models\SourceFile;
use App\Models\User;
use App\Services\Spreadsheet\Workbook;
use App\Services\Upload\UploadService;
use App\Support\Currency;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

/**
 * Runs the real sample files through the actual upload pipeline and reports what came
 * out, so the figures can be checked against the ones the blueprint validated by hand.
 *
 * This imports through UploadService exactly as the web upload does - same validation,
 * same importers, same audit log - rather than reading the files a second way. A number
 * that matches here is a number the app really produces.
 *
 *   php artisan operon:verify-samples --dir=/workspaces/Operational-Dashboard
 *
 * The sample files are real business data and are git-ignored. This command is code and
 * is committed; it simply does nothing if the files are absent.
 */
class VerifySampleFiles extends Command
{
    protected $signature = 'operon:verify-samples
                            {--dir= : Folder holding the sample files}
                            {--fresh : Wipe and rebuild the database first}';

    protected $description = 'Import the real Amazon sample files and report the reconciled figures';

    /** The eight deliveries that make up the multi-delivery PO the blueprint validated. */
    private const EIGHT_DELIVERY_GLOB = 'PACKING LIST_2218*.xlsx';

    public function handle(UploadService $uploads): int
    {
        $dir = rtrim($this->option('dir') ?: base_path('..'), '/');

        if (! is_dir($dir)) {
            $this->error("No such folder: {$dir}");

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->call('migrate:fresh', ['--seed' => true, '--force' => true]);
        }

        $admin = User::role('Admin')->first()
            ?? User::first()
            ?? throw new \RuntimeException('No user to attribute the uploads to. Run php artisan db:seed first.');

        $this->line('');
        $this->info('Importing the sample files through the real upload pipeline…');
        $this->line('');

        $plan = $this->buildPlan($dir);

        if ($plan === []) {
            $this->error("Found no sample files in {$dir}.");

            return self::FAILURE;
        }

        foreach ($plan as [$path, $type, $context]) {
            $this->importOne($uploads, $admin, $path, $type, $context);
        }

        $this->line('');
        $this->report($dir);

        return self::SUCCESS;
    }

    /**
     * Work out which file is which. The stage of a packing list comes from the choice a
     * user would make in the dropdown, not from the filename - files named
     * "PACKING LIST_22183953643-AUG-25.xlsx" carry no stage marker at all. Those eight
     * are the shipped deliveries for the multi-delivery PO, so they load as finals.
     *
     * @return array<int, array{0: string, 1: UploadType, 2: array}>
     */
    private function buildPlan(string $dir): array
    {
        $plan = [];

        // 1. POs first, so packing lines have something to match against.
        foreach (glob($dir.'/POItemExport*.xls') ?: [] as $path) {
            $plan[] = [$path, UploadType::AmazonPoBulk, []];
        }

        foreach (glob($dir.'/PurchaseOrder*.xlsx') ?: [] as $path) {
            // This file has no PO column and its filename carries no PO number either.
            // Its packing lists all say 6QT4G44D, so that is the PO it belongs to.
            $plan[] = [$path, UploadType::AmazonPoSingle, ['po_number' => $this->poNumberForSingleFile($dir)]];
        }

        // 2. Packing lists.
        foreach (glob($dir.'/PACKING LIST_*.xlsx') ?: [] as $path) {
            $name = basename($path);

            $type = match (true) {
                str_contains($name, 'Interim') => UploadType::AmazonInterimPacking,
                str_contains($name, 'Final') => UploadType::AmazonFinalPacking,
                // No stage in the name: these are the shipped deliveries.
                default => UploadType::AmazonFinalPacking,
            };

            $plan[] = [$path, $type, []];
        }

        // 3. The master catalog. After the POs so the link-up of existing PO lines to
        //    their products is exercised the way a real rollout would do it (§K), and
        //    before the cancellations so nothing is waiting on it.
        foreach (glob($dir.'/OperON_Master*.xlsx') ?: [] as $path) {
            $plan[] = [$path, UploadType::MasterSheet, []];
        }

        // 4. Cancellations last, so they can see the PO lines they refer to.
        foreach (glob($dir.'/Cancelled items*.xlsx') ?: [] as $path) {
            $plan[] = [$path, UploadType::AmazonCancellations, []];
        }

        return $plan;
    }

    /** Read the PO number off one of the multi-delivery packing lists. */
    private function poNumberForSingleFile(string $dir): ?string
    {
        $first = glob($dir.'/'.self::EIGHT_DELIVERY_GLOB)[0] ?? null;

        if ($first === null) {
            return null;
        }

        $workbook = Workbook::open($first);

        try {
            $sheet = $workbook->sheet('Simple List');

            return $sheet->text('A5'); // first data row, PO column
        } finally {
            $workbook->close();
        }
    }

    private function importOne(UploadService $uploads, User $admin, string $path, UploadType $type, array $context): void
    {
        $upload = new UploadedFile($path, basename($path), null, null, true);

        $file = $uploads->handle($upload, $type, $admin, $context);

        $label = str_pad(substr(basename($path), 0, 46), 48);

        $this->line(match ($file->status) {
            SourceFileStatus::Imported => sprintf(
                '  <fg=green>✓</> %s %-24s %5d rows, %6s units%s',
                $label,
                $type->stage()?->label() ?? 'PO/cancellations',
                $file->rows_imported,
                number_format((int) data_get($file->summary, 'units', data_get($file->summary, 'units_accepted', 0))),
                $file->rows_unmatched ? "  <fg=yellow>({$file->rows_unmatched} unmatched)</>" : ''
            ),
            SourceFileStatus::Rejected => "  <fg=red>✗</> {$label} REJECTED: {$file->rejection_reason}",
            SourceFileStatus::Failed => "  <fg=red>✗</> {$label} FAILED: {$file->rejection_reason}",
            default => "  <fg=yellow>?</> {$label} {$file->status->label()}",
        });
    }

    private function report(string $dir): void
    {
        $this->components->twoColumnDetail('<options=bold>RESULT</>', '<options=bold>vs the blueprint</>');
        $this->line('');

        $this->reportBulkExport();
        $this->reportMultiDeliveryPo($dir);
        $this->reportInterimLists();
        $this->reportTurnaround();
        $this->reportCancellations();
        $this->reportMasterCatalog();
        $this->reportUnmatched();
    }

    /**
     * The master catalog and the net-margin engine (§S, M6).
     *
     * The check that matters here is the last one: our own P&L, computed from the
     * inputs, against the answer the spreadsheet shipped with. The business already
     * trusts those numbers, so reproducing them exactly is what earns the right to
     * replace the spreadsheet with the app (§S).
     */
    private function reportMasterCatalog(): void
    {
        if (Product::count() === 0) {
            return;
        }

        $this->line('');
        $this->components->twoColumnDetail('<options=bold>Master catalog (§S)</>', '');

        $this->line(sprintf('  Products %s · channel rows %s · identifiers %s',
            number_format(Product::count()),
            number_format(ProductChannelEconomics::count()),
            number_format(ProductIdentifier::count())));

        foreach (ProductChannelEconomics::selectRaw('channel, count(*) as c')->groupBy('channel')->get() as $row) {
            $this->line(sprintf('    %-16s %s', $row->channel->label(), number_format($row->c)));
        }

        $linked = PoLine::whereNotNull('product_id')->count();
        $this->line(sprintf('  PO lines now linked to a catalog product: %s of %s',
            number_format($linked), number_format(PoLine::count())));
        $this->line('  <fg=gray>This is what switches on the brand and category filters M5 built.</>');

        $this->line('');
        $this->line('  Front and back margin vs the sheet\'s own invoice / net-receivable columns');

        // Counted in SQL rather than by loading 2,000 models - this command runs the whole
        // sample set and had no business holding the catalog in memory four times over.
        [$invoiceOk, $invoiceTotal] = $this->agreementCounts('invoice_value', 'invoice_cost_price');
        [$netOk, $netTotal] = $this->agreementCounts('net_receivable', 'net_receivable_imported');

        $this->row('  RSP x front margin = the sheet\'s invoice', $invoiceOk, $invoiceTotal);
        $this->row('  Invoice x back margin = the sheet\'s net', $netOk, $netTotal);

        foreach (ProductChannelEconomics::selectRaw(
            'channel, invoice_pct_of_rsp, net_pct_of_invoice, COUNT(*) as row_count')
            ->whereNotNull('net_pct_of_invoice')
            ->groupBy('channel', 'invoice_pct_of_rsp', 'net_pct_of_invoice')
            ->orderByDesc('row_count')->get() as $group) {
            $this->line(sprintf(
                '    %-14s front %-7s back %-6s  %5s rows   marketplace keeps %s%%',
                $group->channel?->value,
                rtrim(rtrim(number_format((float) $group->invoice_pct_of_rsp, 4), '0'), '.'),
                rtrim(rtrim(number_format((float) $group->net_pct_of_invoice, 4), '0'), '.'),
                number_format($group->row_count),
                round((1 - (float) $group->invoice_pct_of_rsp * (float) $group->net_pct_of_invoice) * 100, 2),
            ));
        }

        $this->line('  <fg=gray>Seller-Central fees (fulfilment / referral / storage / category / other) are');
        $this->line('  stored because the file carries them and are never deducted - we are a vendor.</>');

        $this->line('');
        $this->line('  Our P&L vs the sheet\'s own figures');

        $disagreeing = 0;

        foreach ([
            'net_receivable' => 'Net receivable',
            'cogs' => 'COGS',
            'profit' => 'Profit',
            'margin_pct' => 'Margin %',
        ] as $column => $label) {
            [$agree, $total] = $this->agreementCounts($column, $column.'_imported', 0.01);
            $differ = $total - $agree;
            $disagreeing += $differ;

            $this->line(sprintf(
                '  %s %-38s %s of %s%s',
                $differ === 0 ? '<fg=green>✓</>' : '<fg=yellow>·</>',
                $label.' matches',
                number_format($agree),
                number_format($total),
                $differ === 0 ? '' : '   <fg=yellow>('.$differ.' differ)</>'
            ));
        }

        /*
         * The assertion that matters is not that we always agree with the sheet - §S
         * makes OUR calculation the source of truth, so a disagreement can mean the
         * sheet is wrong. It is that no disagreement passes unremarked.
         *
         * The 49 differences in the real file are the packaging materials: the sheet
         * totals their COGS as zero while each plainly has a product cost. Our figure is
         * the right one, and every row is on the review list.
         */
        $flagged = MasterAnomaly::where('kind', MasterAnomaly::KIND_DERIVED_DISAGREEMENT)->count();

        $this->row('  Every disagreement is flagged, none silent', $flagged, $disagreeing);

        $noMargin = ProductChannelEconomics::whereNull('profit')->count();

        if ($noMargin > 0) {
            $this->line(sprintf(
                '  <fg=gray>%d row(s) have no margin at all: no selling price, so they are things we buy'
                .' and never sell (the packaging materials). Reported as unknown, not as 0%%.</>',
                $noMargin
            ));
        }

        $review = MasterAnomaly::needsReview()->count();
        $notes = MasterAnomaly::open()->where('severity', MasterAnomaly::SEVERITY_NOTE)->count();

        $this->line('');
        $this->line(sprintf('  Flagged for a person: %d needing a decision, %d further note(s)', $review, $notes));
        $this->line('  <fg=gray>Loaded as they are, never silently corrected.</>');

        foreach (MasterAnomaly::needsReview()->get() as $anomaly) {
            $this->line('   <fg=yellow>·</> '.$anomaly->message);
        }
    }

    /**
     * Turnaround and completion (§L).
     *
     * The blueprint has no hand-verified turnaround figures to check against - it
     * defines the rule, not the answer - so this section reports what the engine makes
     * of the real files rather than asserting. The one thing it does assert is the
     * multi-delivery PO: it is 623 units short, so it must NOT read as complete.
     */
    private function reportTurnaround(): void
    {
        $orders = PurchaseOrder::orderBy('po_number')->get();

        if ($orders->isEmpty()) {
            return;
        }

        $benchmark = (int) config('operon.benchmarks.turnaround_days');

        $this->info("Turnaround (benchmark {$benchmark} days)");

        $shipped = $orders->filter(fn (PurchaseOrder $po) => $po->first_shipped_on !== null);

        foreach ($shipped as $po) {
            $this->line(sprintf(
                '     %-10s ordered %s → first shipped %s   %s%s',
                $po->po_number,
                $po->order_date?->format('d M') ?? '   ?  ',
                $po->first_shipped_on->format('d M'),
                $po->is_complete
                    ? 'COMPLETE in '.($po->days_to_complete ?? '?').' days'
                    : ($po->daysOpen() === null ? 'open' : $po->daysOpen().' days and counting'),
                $po->isBreachingBenchmark() ? '  <fg=yellow>over benchmark</>' : ''
            ));
        }

        $undated = $shipped->whereNull('order_date');

        if ($undated->isNotEmpty()) {
            $this->line('');
            $this->line('  <fg=yellow>Note:</> '.$undated->count().' shipped PO(s) carry no order date, so they have a');
            $this->line('  completion date but no day count. The single-PO export has no such column - only a');
            $this->line('  future delivery window, which is not the day the PO was raised. Either upload the');
            $this->line('  same PO from the bulk export, which does carry it, or type the PO date on the');
            $this->line('  upload form. Nothing is invented here.');
            $this->line('');
        }

        $this->line('  POs with at least one shipment: '.$shipped->count().' of '.$orders->count());
        $this->line('  Complete: '.$orders->where('is_complete', true)->count()
            .'  ·  still open: '.$orders->where('is_complete', false)->count());

        // The multi-delivery PO is short of its accepted quantity, so it stays open.
        $multi = $orders->first(fn (PurchaseOrder $po) => $po->shippedDeliveries()->count() >= 8);

        if ($multi !== null) {
            $this->row("PO {$multi->po_number} complete?", $multi->is_complete ? 'yes' : 'no', 'no');
            $this->line('     (623 units short across 3 ASINs, so it is correctly still open)');
        }

        $this->line('');
    }

    private function reportBulkExport(): void
    {
        $file = SourceFile::ofType(UploadType::AmazonPoBulk)->latest('id')->first();

        if ($file === null) {
            return;
        }

        $this->info('Bulk PO export');
        $this->row('Lines imported', $file->rows_imported, 126);
        $this->row('Purchase orders', data_get($file->summary, 'po_count'), 10);
        $this->row('Fulfilment centres', count((array) data_get($file->summary, 'fulfilment_centres')), 7);
        $this->line('  Confirmation rate (accepted ÷ requested): '
            .data_get($file->summary, 'confirmation_rate_pct').'%');
        $this->line('');
    }

    private function reportMultiDeliveryPo(string $dir): void
    {
        $file = SourceFile::ofType(UploadType::AmazonPoSingle)->latest('id')->first();

        if ($file === null) {
            return;
        }

        $poNumber = data_get($file->summary, 'po_numbers.0');
        $po = PurchaseOrder::where('po_number', $poNumber)->first();

        if ($po === null) {
            return;
        }

        $lines = PoLine::where('marketplace', Marketplace::Amazon->value)
            ->where('po_number', $poNumber);

        $accepted = (int) (clone $lines)->sum('qty_accepted');
        $shipped = (int) (clone $lines)->sum('qty_shipped');
        $netAccepted = (int) (clone $lines)->sum('qty_net_accepted');
        $fill = $netAccepted > 0 ? round($shipped / $netAccepted * 100, 2) : null;

        $deliveries = Delivery::whereIn('id', ShipmentLine::where('po_number', $poNumber)->select('delivery_id'))
            ->count();

        $this->info("Multi-delivery PO {$poNumber}");
        $this->row('ASINs on the PO', (clone $lines)->count(), 87);
        $this->row('Units accepted', $accepted, 14740);
        $this->row('Units shipped across all deliveries', $shipped, 14117);
        $this->row('Deliveries linked to this PO', $deliveries, 8);
        $this->row('Fill rate %', $fill, 95.77);

        $unmatched = ShipmentLine::where('po_number', $poNumber)->where('is_unmatched', true)->count();
        $this->row('Packing lines with no matching PO ASIN', $unmatched, 0);

        $over = (clone $lines)->whereColumn('qty_shipped', '>', 'qty_accepted')->count();
        $this->row('Over-shipped ASINs', $over, 0);

        $short = (clone $lines)->whereColumn('qty_shipped', '<', 'qty_net_accepted')->get();
        $this->row('ASINs short of accepted', $short->count(), 3);
        $this->row('Total shortfall units', $short->sum(fn ($l) => $l->qty_net_accepted - $l->qty_shipped), 623);

        foreach ($short as $line) {
            $this->line(sprintf(
                '     %s  accepted %s, shipped %s → short %s   <fg=gray>%s</>',
                $line->sku_id,
                number_format($line->qty_net_accepted),
                number_format($line->qty_shipped),
                number_format($line->qty_net_accepted - $line->qty_shipped),
                str($line->title ?? '')->limit(38)
            ));
        }

        $this->line('');
    }

    private function reportInterimLists(): void
    {
        $this->info('Interim packing lists (carton-total rows skipped)');

        $expected = [
            '22161389743' => ['units' => 468, 'items' => 85, 'skipped' => 11, 'pos' => 5],
            '22161964743' => ['units' => 641, 'items' => 9, 'skipped' => 0, 'pos' => 2],
        ];

        foreach ($expected as $asn => $want) {
            $delivery = Delivery::where('asn', $asn)->first();

            if ($delivery === null) {
                continue;
            }

            $lines = $delivery->interimLines();

            $this->line("  ASN {$asn} ({$delivery->internal_ref}) — FC "
                .($delivery->fc_code ?? 'not derivable from the POs loaded'));
            $this->row('  units booked', $delivery->units_interim, $want['units']);
            $this->row('  item rows', (clone $lines)->count(), $want['items']);
            $this->row('  distinct POs', (clone $lines)->distinct()->count('po_number'), $want['pos']);

            $file = $delivery->interim_source_file_id
                ? SourceFile::find($delivery->interim_source_file_id) : null;

            if ($file) {
                $this->row('  "Carton total" rows skipped',
                    data_get($file->summary, 'carton_total_rows_skipped'), $want['skipped']);
            }

            if ($delivery->has_final) {
                $this->line(sprintf(
                    '    → final shipped %s units; shortfall %s units / %s',
                    number_format($delivery->units_final),
                    number_format($delivery->shortfall_units),
                    Currency::plain($delivery->shortfall_value, $delivery->currency)
                ));
            }

            $this->line('');
        }
    }

    private function reportCancellations(): void
    {
        if (Cancellation::count() === 0) {
            return;
        }

        $this->info('Cancellations');
        $this->line('  Rows stored: '.Cancellation::count());
        $this->line('  Units cancelled: '.number_format((int) Cancellation::sum('qty_cancelled')));

        $matched = Cancellation::where('is_unmatched', false)->get();

        $this->line('  Matched to a PO line: '.$matched->count()
            .'  ·  waiting for their PO: '.Cancellation::where('is_unmatched', true)->count());
        $this->line('  Units actually netted off accepted: '
            .number_format((int) Cancellation::sum('qty_honoured')));
        $this->line('  Parked for a deliver-anyway / pull-it decision: '
            .Cancellation::needsDecision()->count());

        if ($matched->isEmpty()) {
            $this->line('');
            $this->line('  <fg=yellow>Note:</> none of these cancellations name a PO that is in the PO files');
            $this->line('  supplied, so nothing nets here. They are stored and will net by themselves');
            $this->line('  when those POs are uploaded. The netting arithmetic itself is proven by the');
            $this->line('  AmazonImportTest suite instead.');
        }

        foreach ($matched as $cancellation) {
            $line = $cancellation->poLine;

            $this->line(sprintf(
                '     %s / %s  cancelled %d  → accepted %d, net accepted %d  [%s]%s',
                $cancellation->po_number,
                $cancellation->sku_id,
                $cancellation->qty_cancelled,
                $line?->qty_accepted ?? 0,
                $line?->qty_net_accepted ?? 0,
                $cancellation->resolution->label(),
                $cancellation->hasConfirmedQtyMismatch() ? ' <fg=yellow>confirmed-qty mismatch</>' : ''
            ));
        }

        /*
         * The join is PO + ASIN, never ASIN alone (§B). Some cancelled ASINs DO exist in
         * our data, but on a different PO - so they must NOT net. Showing them proves the
         * join key is doing its job: netting on ASIN alone would have wrongly cut units
         * off the multi-delivery PO.
         */
        $crossPo = Cancellation::where('is_unmatched', true)->get()
            ->map(function (Cancellation $cancellation) {
                $elsewhere = PoLine::where('marketplace', Marketplace::Amazon->value)
                    ->where('sku_id', $cancellation->sku_id)
                    ->where('po_number', '!=', $cancellation->po_number)
                    ->first();

                return $elsewhere === null ? null : [$cancellation, $elsewhere];
            })
            ->filter()
            ->values();

        if ($crossPo->isNotEmpty()) {
            $this->line('');
            $this->line('  <options=bold>Join-key check — same ASIN, different PO (must NOT net):</>');

            foreach ($crossPo as [$cancellation, $elsewhere]) {
                $this->line(sprintf(
                    '     cancellation says PO %s / %s (%d units); we hold that ASIN on PO %s '
                    .'with %d accepted, %d shipped → correctly left alone',
                    $cancellation->po_number,
                    $cancellation->sku_id,
                    $cancellation->qty_cancelled,
                    $elsewhere->po_number,
                    $elsewhere->qty_accepted,
                    $elsewhere->qty_shipped
                ));
            }
        }

        $this->line('');
    }

    private function reportUnmatched(): void
    {
        $unmatched = ShipmentLine::where('is_unmatched', true)->count();

        if ($unmatched === 0) {
            return;
        }

        $this->info('Packing lines waiting for their PO (expected during rollout, §K)');
        $this->line("  {$unmatched} line(s), across these POs:");

        $byPo = ShipmentLine::where('is_unmatched', true)
            ->selectRaw('po_number, COUNT(*) as lines, SUM(qty) as units')
            ->groupBy('po_number')
            ->orderByDesc('units')
            ->get();

        foreach ($byPo as $row) {
            $this->line(sprintf('     %-12s %3d lines, %6s units', $row->po_number, $row->lines, number_format((int) $row->units)));
        }

        $this->line('  These are stored, not dropped, and link up automatically when those POs are uploaded.');
        $this->line('');
    }

    /** Print an actual-vs-expected line with a tick or a cross. */
    /**
     * How many rows agree between two columns, and how many could be compared at all.
     * Done in SQL so the whole catalog never has to be held in memory.
     *
     * @return array{0:int, 1:int}
     */
    private function agreementCounts(string $ours, string $theirs, ?float $absolute = null): array
    {
        // Both sides must exist to be compared. A row where the sheet says 0 and we say
        // "unknown" is not an agreement and not a disagreement - it is not a comparison,
        // and counting it either way would be the null-is-zero mistake this codebase
        // avoids everywhere else.
        $base = ProductChannelEconomics::query()
            ->whereNotNull($theirs)
            ->whereNotNull($ours)
            ->when($absolute === null, fn ($q) => $q->where($theirs, '>', 0));

        $total = (clone $base)->count();

        // Agreement is within a fils, or within a tenth of a percent for larger figures.
        $agree = (clone $base)
            ->whereRaw(
                $absolute === null
                    ? "ABS({$ours} - {$theirs}) <= MAX(0.01, ABS({$theirs}) * 0.001)"
                    : "ABS({$ours} - {$theirs}) < {$absolute}"
            )
            ->count();

        return [$agree, $total];
    }

    private function row(string $label, mixed $actual, mixed $expected): void
    {
        $ok = is_float($expected) || is_float($actual)
            ? abs((float) $actual - (float) $expected) < 0.01
            : (string) $actual === (string) $expected;

        $this->line(sprintf(
            '  %s %-42s %12s   expected %s',
            $ok ? '<fg=green>✓</>' : '<fg=red>✗</>',
            $label,
            is_numeric($actual) ? number_format((float) $actual, is_float($expected) ? 2 : 0) : (string) $actual,
            is_numeric($expected) ? number_format((float) $expected, is_float($expected) ? 2 : 0) : (string) $expected
        ));
    }
}
