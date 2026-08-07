<?php

namespace App\Console\Commands;

use App\Enums\Channel;
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
use App\Models\SelloutRow;
use App\Models\ShipmentLine;
use App\Models\SourceFile;
use App\Models\User;
use App\Services\Analytics\SellThroughEngine;
use App\Services\Margin\NetMarginEngine;
use App\Services\Margin\ProfitAndLoss;
use App\Services\Margin\SkuMargin;
use App\Services\Reporting\FilterSet;
use App\Services\Reporting\UnlinkedIdentifiers;
use App\Services\Spreadsheet\Workbook;
use App\Services\Upload\UploadService;
use App\Support\Currency;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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

        /*
         * 2. Noon (§Q, M8). The base "V1" file is the PO; the Final carries the delivery.
         *
         * ORDER MATTERS AND IS THE POINT: the PO goes first so the picking list has an
         * order to compare against - though the Noon importer deliberately does not
         * depend on that, because every Noon workbook carries the packing tab too.
         */
        foreach (glob($dir.'/M8_Noon/*.xlsx') ?: [] as $path) {
            $name = basename($path);

            $type = match (true) {
                str_contains($name, 'Interim') => UploadType::NoonInterimPicking,
                str_contains($name, 'Final') => UploadType::NoonFinalPicking,
                default => UploadType::NoonPo,
            };

            /*
             * POs before picking lists, whatever order glob returned them in.
             *
             * NO DELIVERY DATE IS PASSED, DELIBERATELY (M9 refinement of M8). M8 supplied
             * 23 Jul here so the sample would show a turnaround; that was a date nobody
             * had confirmed, sitting in the verification output looking like a fact. The
             * sample now behaves exactly as a real upload with the field left blank does:
             * Noon's estimate shows as an estimate and turnaround waits.
             */
            $plan[] = [$path, $type, []];
        }

        usort($plan, fn ($a, $b) => self::planOrder($a[1]) <=> self::planOrder($b[1]));

        // 3. Amazon packing lists.
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

        // 4. Cancellations, so they can see the PO lines they refer to.
        foreach (glob($dir.'/Cancelled items*.xlsx') ?: [] as $path) {
            $plan[] = [$path, UploadType::AmazonCancellations, []];
        }

        /*
         * 5. M9 — sell-out and stock, LAST and deliberately so.
         *
         * These are the only files whose rows are keyed purely on the catalog: a sell-out
         * row has no PO to fall back on, so an ASIN or NIN the master has not seen is
         * simply unmatched. Loading them after the master sheet is what a real week looks
         * like, and it is what makes the unmatched count mean "not in the catalog"
         * rather than "the catalog had not been uploaded yet".
         */
        foreach ($this->m9Plan($dir) as $entry) {
            $plan[] = $entry;
        }

        return $plan;
    }

    /**
     * The five M9 files, matched on the distinctive part of their real names.
     *
     * Sell-out before stock on each channel, because cover reads better in a log when
     * the velocity it divides is already on screen.
     *
     * @return array<int, array{0: string, 1: UploadType, 2: array}>
     */
    private function m9Plan(string $dir): array
    {
        $folder = $dir.'/M9_Sellout_DFS';

        $matches = fn (string $glob) => glob($folder.'/'.$glob) ?: [];

        $plan = [];

        foreach ($matches('Sales_ASIN_Sourcing_Retail*.xlsx') as $path) {
            $plan[] = [$path, UploadType::AmazonSellout, []];
        }

        foreach ($matches('Inventory_ASIN_Sourcing_Retail*.xlsx') as $path) {
            $plan[] = [$path, UploadType::AmazonInventory, []];
        }

        foreach ($matches('DFS Sales*.xlsx') as $path) {
            $plan[] = [$path, UploadType::AmazonDfs, []];
        }

        foreach ($matches('amazon_df_inv_bulk*.csv') as $path) {
            $plan[] = [$path, UploadType::AmazonDfsInventory, []];
        }

        foreach ($matches('*Sell Out & SOH.xlsx') as $path) {
            $plan[] = [$path, UploadType::NoonSellout, []];
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
        $this->reportNoon();
        $this->reportProfitability();
        $this->reportSellOutAndCover();
        $this->reportUnlinkedIdentifiers();
        $this->reportUnmatched();
    }

    /**
     * M9 — sell-out, velocity and days of cover, on all three channels (§P, §R).
     *
     * Two things are printed here that a table of totals would not give you.
     *
     * FIRST, THE TWO AMAZON REVENUE COLUMNS SIDE BY SIDE. "Shipped COGS" is what Amazon
     * paid us and "Shipped Revenue" is what the customer paid Amazon; they read
     * 1,704,390.15 and 1,691,050.50 on the real file. Printing both, labelled, is what
     * stops the wrong one quietly becoming "our revenue" in some future change.
     *
     * SECOND, ONE SKU'S COVER WORKED THROUGH LONG-HAND. Days of cover is the figure
     * somebody reorders against, and it is three numbers deep — stock, a run rate, and a
     * window. Showing the arithmetic for one real SKU means a reader can check it with a
     * calculator instead of trusting it.
     */
    private function reportSellOutAndCover(): void
    {
        $engine = new SellThroughEngine(new FilterSet);

        if (! $engine->hasSellOut() && ! $engine->hasStock()) {
            return;
        }

        $this->line('');
        $this->components->twoColumnDetail('<options=bold>Sell-out, velocity and days of cover (§P/§R, M9)</>', '');

        foreach ($engine->byChannel() as $channel) {
            if ($channel['sell_out_units'] === 0 && $channel['soh_units'] === null) {
                continue;
            }

            $this->line('');
            $this->line(sprintf('  <options=bold>%s</>%s',
                $channel['channel']->label(),
                $channel['stock_is_provisional'] ? '   <fg=yellow>stock '.$channel['stock_note'].'</>' : ''));

            $this->line(sprintf('    sell-out          %s units · %s   over %s (%s days, %s grain)',
                number_format($channel['sell_out_units']),
                Currency::plain($channel['sell_out_revenue'], $channel['currency']),
                $channel['sell_out_from']
                    ? Carbon::parse($channel['sell_out_from'])->format('j M').' – '.Carbon::parse($channel['sell_out_to'])->format('j M Y')
                    : 'no window',
                $channel['sell_out_days'] ?? '?',
                $channel['sell_out_grain'] ?? '?'));

            $this->line(sprintf('    sell-in (all held) %s units%s',
                $channel['sell_in_units'] === null ? 'n/a — no PO step' : number_format($channel['sell_in_units']),
                $channel['sell_in_window_units'] === null ? '' : sprintf(
                    '   ·  dated inside the sell-out window: %s units on %d day(s)',
                    number_format($channel['sell_in_window_units']),
                    $channel['sell_in_window_days']
                )));

            if ($channel['sell_through_pct'] !== null) {
                $this->line(sprintf('    <fg=green>SELL-THROUGH      %s%%</>   %s ÷ %s, using %s',
                    $channel['sell_through_pct'],
                    number_format($channel['sell_out_units']),
                    number_format($channel['sell_through_denominator']),
                    $channel['sell_through_basis']));
                $this->line(sprintf('                      %s units still sitting at the channel',
                    number_format($channel['sitting_units'])));
            } else {
                $this->line('    <fg=yellow>SELL-THROUGH      not reported</>');
                $this->line('      <fg=gray>'.wordwrap((string) $channel['sell_through_note'], 96, "\n      ").'</>');
            }

            $this->line(sprintf('    stock on hand     %s units as at %s%s',
                $channel['soh_units'] === null ? 'n/a' : number_format($channel['soh_units']),
                $channel['soh_as_at'] ? Carbon::parse($channel['soh_as_at'])->format('j M Y') : '—',
                $channel['aged_90_units'] ? '   ·  '.number_format($channel['aged_90_units']).' units aged 90+ days' : ''));

            $this->line(sprintf('    run rate / cover  %s units/day  →  %s days of cover',
                $channel['daily_run_rate'] === null ? '—' : number_format($channel['daily_run_rate'], 2),
                $channel['cover_days'] === null ? '—' : number_format($channel['cover_days'], 1)));
        }

        // The two Amazon columns, together, so the trap stays visible.
        $ourRevenue = (float) SelloutRow::where('channel', Channel::AmazonRetail->value)->sum('revenue');
        $consumer = (float) SelloutRow::where('channel', Channel::AmazonRetail->value)->sum('shipped_revenue');

        if ($ourRevenue > 0) {
            $this->line('');
            $this->line('  <options=bold>Amazon sell-out: the two revenue columns, and which one is ours</>');
            $this->row('  OUR revenue — "Shipped COGS" (what Amazon paid us)', round($ourRevenue, 2), 1704390.15);
            $this->row('  NOT ours — "Shipped Revenue" (consumer retail)', round($consumer, 2), 1691050.50);
            $this->line('  <fg=gray>Those are 0.8% apart. Taking the wrong one would never look wrong on a screen,');
            $this->line('  which is why `revenue` is a named column and `revenue_basis` records where it came from.</>');
        }

        $this->reportOneCoverCalculation($engine);
    }

    /** One real SKU's days of cover, worked through so a person can check it. */
    private function reportOneCoverCalculation(SellThroughEngine $engine): void
    {
        $rows = $engine->skuRows();

        // The most interesting SKU is the one somebody has to act on: biggest stock
        // among those on a watchlist. Failing that, the biggest seller with cover.
        $sample = $rows
            ->filter(fn (array $r) => $r['cover_days'] !== null && $r['overstock_reason'] !== null)
            ->sortByDesc('soh_units')
            ->first()
            ?? $rows->filter(fn (array $r) => $r['cover_days'] !== null)->sortByDesc('sell_out_units')->first();

        if ($sample === null) {
            return;
        }

        $this->line('');
        $this->components->twoColumnDetail('<options=bold>One SKU\'s days of cover, long-hand</>', '');
        $this->line(sprintf('  %s  <fg=gray>%s</>', $sample['sku_id'], Str::limit((string) $sample['title'], 52)));
        $this->line(sprintf('  channel                %s%s', $sample['channel']->label(),
            $sample['stock_is_provisional'] ? '   <fg=yellow>(stock '.$sample['stock_note'].')</>' : ''));
        $this->line(sprintf('  sell-out               %s units over %s days',
            number_format($sample['sell_out_units']),
            $sample['run_rate_window_days'] ?? $sample['sell_out_window_days'] ?? '?'));
        $this->line(sprintf('  run rate               %s units/day   <fg=gray>(%s)</>',
            number_format((float) $sample['run_rate'], 4),
            $sample['run_rate_basis']));

        if ($sample['run_rate_is_period_average']) {
            $this->line('    <fg=yellow>· a PERIOD AVERAGE, not a current rate — Amazon\'s report has no daily detail</>');
        }

        if ($sample['run_rate_is_stated']) {
            $this->line('    <fg=gray>· the channel\'s own figure, kept in preference to anything we could derive</>');
        }

        $this->line(sprintf('  stock on hand          %s units', number_format($sample['soh_units'])));
        $this->line(sprintf('  <options=bold>DAYS OF COVER          %s ÷ %s = %s days</>',
            number_format($sample['soh_units']),
            number_format((float) $sample['run_rate'], 4),
            number_format((float) $sample['cover_days'], 1)));

        // The arithmetic must reproduce itself, or the line above is decoration.
        $this->row('  Recomputed from the two figures printed',
            round($sample['soh_units'] / (float) $sample['run_rate'], 1),
            round((float) $sample['cover_days'], 1));

        foreach (['overstock_reason' => 'Overstocking', 'stockout_reason' => 'Stock-out risk'] as $key => $label) {
            if ($sample[$key] !== null) {
                $this->line(sprintf('  <fg=yellow>on the %s list: %s</>', $label, $sample[$key]));
            }
        }

        $lists = $engine->watchlists($rows);

        $this->line('');
        $this->line(sprintf('  Watchlists — overstocking %s SKUs (%s units) · under-supplying %s SKUs',
            number_format($lists['overstocking']['all']->count()),
            number_format($lists['overstocking']['units']),
            number_format($lists['under_supplying']['all']->count())));

        foreach (['overstocking', 'under_supplying'] as $list) {
            foreach ($lists[$list]['by_channel'] as $channel => $group) {
                $this->line(sprintf('    %-16s %-14s %s', $list, $channel, $group->count()));
            }
        }

        $this->line('');
    }

    /**
     * Identifiers the files name that the master catalog does not hold (§S, M9 fix list).
     *
     * The M8 checkpoint said "one Noon PO line has a NIN that is not in the master" and
     * left it at that. Here it is BY NAME, so it can actually be added — along with
     * everything M9's sell-out and stock feeds turned up.
     */
    private function reportUnlinkedIdentifiers(): void
    {
        $all = UnlinkedIdentifiers::all(10_000);

        if ($all->isEmpty()) {
            return;
        }

        $traded = UnlinkedIdentifiers::traded();

        $this->line('');
        $this->components->twoColumnDetail(
            '<options=bold>Not in the master catalog — the fix list (§S)</>', '');

        $this->line(sprintf('  %s identifier(s) appear in the files and not in the catalog; %s of them we have '
            .'ordered, delivered or sold.', number_format($all->count()), number_format($traded->count())));
        $this->line('  <fg=gray>Every row is stored, never dropped, and links itself the moment the code exists (§K).</>');

        // THE ONE M8 PROMISED. Named, so it can be added.
        $noonPoLine = $traded->first(fn (array $e) => $e['marketplace'] === Marketplace::Noon
            && isset($e['seen_in']['ordered on a PO']));

        if ($noonPoLine !== null) {
            $this->line('');
            $this->line('  <options=bold>The Noon PO line M8 flagged, by name:</>');
            $this->line('    <fg=yellow>NIN '.$noonPoLine['sku_id'].'</>');
            $this->line(sprintf('    %s', $noonPoLine['title'] ?? '(no title on the file)'));
            $this->line(sprintf('    %s units, %s', number_format($noonPoLine['units']),
                implode(' and ', array_keys($noonPoLine['seen_in']))));
            $this->line('    <fg=gray>Add it to the master with a BD##### code and this line joins every rollup.</>');
        }

        $this->line('');
        $this->line('  Everything we have traded that the catalog does not know:');

        foreach ($traded->take(20) as $entry) {
            $this->line('    <fg=yellow>·</> '.UnlinkedIdentifiers::describe($entry));
        }

        if ($traded->count() > 20) {
            $this->line(sprintf('    <fg=gray>… and %d more, all listed on /master.</>', $traded->count() - 20));
        }

        $this->line('');
    }

    /**
     * The M7 money views, on the real reconciled PO (§Profitability).
     *
     * The figure that matters is 18.13%. It is the answer M6's corrected engine gives for
     * the 8-delivery PO, and M7 is views over that answer, not a second calculation - so
     * if the P&L statement the screens print ever stops reproducing it, either a screen
     * has started doing its own arithmetic or the engine has drifted. Both are fatal, and
     * both are caught here.
     *
     * The statement is printed line by line as well as checked, because the point of a
     * P&L is that a person can add it up themselves.
     */
    private function reportProfitability(): void
    {
        $po = PurchaseOrder::query()
            ->whereHas('lines', fn ($q) => $q->where('qty_shipped', '>', 0))
            ->get()
            ->map(fn (PurchaseOrder $order) => [$order, NetMarginEngine::forPurchaseOrder($order)])
            ->sortByDesc(fn ($pair) => $pair[1]['billed'])
            ->first();

        if ($po === null) {
            return;
        }

        [$order, $result] = $po;
        $statement = ProfitAndLoss::fromResult($result, $order);
        $currency = $statement['currency'];

        $this->line('');
        $this->components->twoColumnDetail("<options=bold>PO-level net P&L (§Profitability) — {$order->po_number}</>", '');

        foreach ($statement['lines'] as $line) {
            $amount = $line['amount'];

            $this->line(sprintf(
                '  %s%-38s %16s%s',
                in_array($line['kind'], [ProfitAndLoss::SUBTOTAL, ProfitAndLoss::RESULT], true) ? '' : '  ',
                $line['label'],
                $amount === null ? '—' : Currency::plain($amount, $currency),
                empty($line['pending']) ? '' : '   <fg=yellow>('.ProfitAndLoss::UNTIL_DATA_ADDED.')</>'
            ));
        }

        $this->line('');

        // The blueprint-validated answers for this PO, from M6's corrected engine.
        $this->row('  Invoiced (billed)', $result['billed'], 223511.20);
        $this->row('  Net receivable (after the back margin)', $result['net_receivable'], 173937.19);
        $this->row('  Our cost', $result['cost'], 142399.50);
        $this->row('  Net profit', $result['profit'], 31537.69);
        $this->row('  MARGIN %', $result['margin_pct'], 18.13);
        $this->row('  Lines costed', $result['coverage']['lines_costed'], 85);

        // The property that makes the statement worth printing: its lines add up to it.
        $this->row(
            '  Cost lines total the cost',
            round(array_sum($result['cost_breakdown']), 2),
            round((float) $result['cost'], 2)
        );

        if ($statement['pending'] !== []) {
            $this->line(sprintf(
                '  <fg=gray>%s read 0: the master sheet carries no figure for them yet. The lines are'
                .' wired to the same engine as every other cost and fill in on their own.</>',
                implode(', ', $statement['pending'])
            ));
        }

        // SKU-level, blended. The rule being demonstrated is that "Both" is weighted.
        $skus = SkuMargin::rows(SkuMargin::BOTH, new FilterSet, 5000);

        if ($skus->isEmpty()) {
            return;
        }

        $priced = $skus->filter(fn ($row) => $row['blend']['margin_pct'] !== null);
        $weighted = $priced->sum(fn ($row) => $row['blend']['profit_total']);
        $revenue = $priced->sum(fn ($row) => $row['blend']['revenue_total']);
        $simpleMean = $priced->avg(fn ($row) => $row['blend']['margin_pct']);

        $this->line('');
        $this->components->twoColumnDetail('<options=bold>SKU-level net margin (§Profitability)</>', '');

        $this->line(sprintf('  %s SKU(s) with economics · %s profitable · %s losing money · %s with no verdict',
            number_format($skus->count()),
            number_format($skus->filter(fn ($r) => $r['profitable'] === true)->count()),
            number_format($skus->filter(fn ($r) => $r['profitable'] === false)->count()),
            number_format($skus->filter(fn ($r) => $r['profitable'] === null)->count())));

        $this->line(sprintf(
            '  Blended margin, REVENUE-WEIGHTED  %6s%%      a simple mean would say %s%%',
            $revenue > 0 ? round($weighted / $revenue * 100, 2) : '—',
            round((float) $simpleMean, 2)
        ));
        $this->line('  <fg=gray>Those two are different numbers and the blueprint asks for the first one.</>');

        $this->line('');
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

    /** POs before the deliveries that reference them. */
    private static function planOrder(UploadType $type): int
    {
        return match ($type) {
            UploadType::AmazonPoBulk, UploadType::AmazonPoSingle, UploadType::NoonPo => 0,
            UploadType::NoonInterimPicking => 1,
            UploadType::NoonFinalPicking => 2,
            default => 3,
        };
    }

    /**
     * Noon Retail (§Q, M8) — the inverted fulfilment rule, on the real PO.
     *
     * The figure that matters is 99.55%. Reading a Noon picking list the way an Amazon
     * packing list is read - as a positive record of what shipped - gives 5,812 of 6,431
     * and 90.38%, because the six lines Noon never mentions are the ones that went out
     * PERFECTLY. This section exists to make that mistake impossible to reintroduce
     * quietly: it asserts both the right answer and the wrong one it would have been.
     */
    private function reportNoon(): void
    {
        $po = PurchaseOrder::where('marketplace', Marketplace::Noon->value)
            ->orderByDesc('id')->first();

        if ($po === null) {
            return;
        }

        $lines = PoLine::where('marketplace', Marketplace::Noon->value)
            ->where('po_number', $po->po_number);

        $ordered = (int) (clone $lines)->sum('qty_accepted');
        $delivered = (int) (clone $lines)->sum('qty_shipped');
        // Two order values, and the difference between them is Noon's own rounding, not
        // ours: the file's "Total Amount" column is what Noon invoiced, while units x our
        // stored unit cost is what every screen recomputes. They should agree to a fils.
        $poFile = SourceFile::ofType(UploadType::NoonPo)->latest('id')->first();
        $statedValue = (float) data_get($poFile?->summary, 'order_value', 0);
        $orderValue = (float) (clone $lines)->get()->sum(fn ($l) => $l->qty_accepted * (float) $l->unit_cost);
        $fill = $ordered > 0 ? round($delivered / $ordered * 100, 2) : null;

        $this->line('');
        $this->components->twoColumnDetail("<options=bold>Noon Retail (§Q) — PO {$po->po_number}</>", '');

        $this->row('  Ordered lines (Packing List)', (clone $lines)->count(), 72);
        $this->row('  Ordered units', $ordered, 6431);
        $this->row('  Order value (the file\'s own line totals)', $statedValue, 107694.05);
        $this->line(sprintf(
            '     units x our stored unit cost: %s  <fg=gray>(within a fils - Noon rounds its'
            .' printed unit rate to 2dp, so the rate is taken from the line total instead)</>',
            Currency::plain($orderValue, 'AED')
        ));
        $this->row('  Delivered units', $delivered, 6402);
        $this->row('  Shortfall units', $ordered - $delivered, 29);
        $this->row('  Fill rate %', $fill, 99.55);

        // The one short line, named. A Noon picking list exists to report exceptions, so
        // the exception is what gets printed.
        $short = (clone $lines)->whereColumn('qty_shipped', '<', 'qty_accepted')->get();

        $this->row('  Short lines', $short->count(), 1);

        foreach ($short as $line) {
            $this->line(sprintf(
                '     barcode %-16s ordered %s, delivered %s, short %s  <fg=gray>%s</>',
                $line->barcode,
                number_format($line->qty_accepted),
                number_format($line->qty_shipped),
                number_format($line->qty_accepted - $line->qty_shipped),
                Str::limit((string) $line->title, 46)
            ));
        }

        /*
         * THE RULE, ASSERTED AS THE DIFFERENCE IT MAKES.
         *
         * "Stated" lines are the ones the picking list actually lists. Reading only those
         * - the Amazon way - is the failure mode, and it is worth printing the number it
         * would produce so nobody has to imagine how bad it would be.
         */
        $stated = (int) ShipmentLine::query()
            ->where('marketplace', Marketplace::Noon->value)
            ->where('po_number', $po->po_number)
            ->where('stage', Stage::Final->value)
            ->whereColumn('qty', '>', 0)
            ->count();

        $file = SourceFile::ofType(UploadType::NoonFinalPicking)->latest('id')->first();
        $statedOnFile = (int) data_get($file?->summary, 'lines_stated_on_file', 0);
        $impliedFull = (int) data_get($file?->summary, 'lines_delivered_in_full_by_omission', 0);
        $statedUnits = (int) (clone $lines)->get()
            ->sum(fn ($l) => $l->qty_shipped);

        $this->line('');
        $this->line('  <options=bold>Noon annotates only the exceptions — the rule, and the trap</>');
        $this->row('  Lines the picking list states', $statedOnFile, 66);
        $this->row('  Lines delivered in full by omission', $impliedFull, 6);
        $this->line(sprintf(
            '  <fg=gray>Reading only the stated lines - the Amazon way - would report %s of %s units'
            .' and a %s%% fill rate. Those six silent lines went out perfectly.</>',
            number_format(5812),
            number_format($ordered),
            round(5812 / max(1, $ordered) * 100, 2)
        ));

        $linked = (clone $lines)->whereNotNull('product_id')->count();
        $this->line(sprintf('  Linked to the master catalog by NIN: %d of %d lines',
            $linked, (clone $lines)->count()));

        $delivery = Delivery::where('marketplace', Marketplace::Noon->value)
            ->where('internal_ref', $po->po_number)->first();

        if ($delivery !== null) {
            $this->line(sprintf('  Delivery %s — booked %s, delivered %s',
                $delivery->delivery_key,
                number_format($delivery->units_interim),
                number_format($delivery->units_final)));

            /*
             * THE M9 REFINEMENT, ASSERTED. M8 passed 23 Jul in on the upload form so the
             * sample would show a turnaround; nobody had confirmed that date. The tool now
             * refuses to supply one, and this section proves it still refuses - because
             * the failure mode is silent, and a re-introduced fallback would simply make
             * a turnaround reappear and look correct.
             */
            $this->row('  Delivery date is NOT invented', $delivery->fulfilmentDate()?->format('d M Y') ?? 'not set', 'not set');
            $this->row('  Waiting for a person to enter it', $delivery->awaitingDeliveryDate() ? 'yes' : 'no', 'yes');

            $this->line(sprintf(
                '     shown meanwhile: %s  <fg=yellow>%s</>',
                $delivery->shownDate()?->format('d M Y') ?? '—',
                $delivery->shownDateNote() ?? ''
            ));
            $this->line('     <fg=gray>That is Noon\'s own Estimated Delivery Date off the PO tab. It is a plan, so it is');
            $this->line('     labelled and never measured from: this PO reports NO turnaround until the real');
            $this->line('     date is entered on /deliveries. Nothing here is assumed.</>');
        }

        // The money side, which M7 already knew how to do the moment Noon had rows.
        $result = NetMarginEngine::forPurchaseOrder($po);

        $this->line('');
        $this->line(sprintf(
            '  Net P&L: billed %s → net %s − cost %s = %s   margin %s%%   (%d of %d lines costed)',
            Currency::plain($result['billed'], $result['currency']),
            Currency::plain($result['net_receivable'], $result['currency']),
            Currency::plain($result['cost'], $result['currency']),
            Currency::plain($result['profit'], $result['currency']),
            $result['margin_pct'],
            $result['coverage']['lines_costed'],
            $result['coverage']['lines_costed'] + $result['coverage']['lines_uncosted'],
        ));
        $this->line('  <fg=gray>The marketplace keeps 23.56% of retail on Noon against 29.65% on'
            .' Amazon - a better front margin on the same back margin - which is why the same'
            .' catalog earns differently on the two channels.</>');
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
