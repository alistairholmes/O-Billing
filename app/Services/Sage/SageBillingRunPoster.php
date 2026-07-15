<?php

declare(strict_types=1);

namespace App\Services\Sage;

use App\Models\BillingRun;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Posts a completed O-Billing billing run straight into Sage Evolution as
 * POSTED customer invoices on the `sage_write` connection — no operator step.
 *
 * Every O-Billing invoice line is one Sage invoice document on that charge's
 * own debtor account ({stand}-{token}-…), written with the exact footprint
 * Evolution's Invoice Maintenance leaves when it posts (modelled row-for-row
 * on a reference invoice posted in this database):
 *
 *   InvNum                    posted document (DocState 4), numbered from the
 *                             inventory defaults counter (INV + 8 digits);
 *                             ExtOrderNum carries the O-Billing invoice number
 *   _btblInvoiceLines(+Details) the single charge line (service item, qty 1)
 *   PostAR                    the debtor transaction: debit incl. amount, home
 *                             (ZWG) and foreign (USD) values, open Outstanding
 *   PostGL                    the balanced double entry, home currency:
 *                               DR  debtors control   (client class)
 *                               CR  output VAT        (class tax control; taxed lines only)
 *                               CR  revenue           (service item's inventory group)
 *   Client                    running balance (DCBalance / fForeignBalance)
 *   StDfTbl / Entities        invoice-number and audit-number counters
 *
 * GL wiring is resolved live from Sage: the client's class (CliClass) gives the
 * debtors-control and VAT-control accounts, and the Sage service item mapped to
 * that class (config sage.posting.class_items) gives the revenue account via
 * its inventory group. Exempt lines (no O-Billing tax) use the exempt tax type
 * and post no VAT row. Evolution writes two zero-value stock/COS GL rows for
 * service items; those carry no value and are deliberately not replicated.
 *
 * Home amounts are derived as excl = round(excl×rate), incl = round(incl×rate),
 * tax = incl − excl, so every audit set balances to the cent by construction;
 * a SQL assertion re-checks inside the transaction before commit.
 *
 * `preview()` builds everything without writing; `post()` writes it all in one
 * transaction. A run whose invoice numbers already appear on Sage documents is
 * refused (double-post guard) unless forced. `previewInvoice()` / `postInvoice()`
 * do the same for a single O-Billing invoice.
 */
final class SageBillingRunPoster
{
    private const CONN = 'sage_write';

    /** @return array<string,mixed> */
    public function preview(BillingRun $run): array
    {
        return $this->build($this->runInvoices($run), $run->period_month);
    }

    /** @return array<string,mixed> */
    public function post(BillingRun $run, bool $force = false): array
    {
        return $this->commit($this->preview($run), "run {$run->run_number}", $force);
    }

    /** @return array<string,mixed> */
    public function previewInvoice(Invoice $invoice): array
    {
        $invoice->loadMissing(['customer', 'lines.service.serviceType']);

        return $this->build(collect([$invoice]), $invoice->period_month);
    }

    /** @return array<string,mixed> */
    public function postInvoice(Invoice $invoice, bool $force = false): array
    {
        return $this->commit($this->previewInvoice($invoice), "invoice {$invoice->invoice_number}", $force);
    }

    /**
     * Guard, then write a built document set to Sage in one transaction.
     *
     * @param  array<string,mixed>  $build
     * @return array<string,mixed>
     */
    private function commit(array $build, string $subject, bool $force): array
    {
        if ($build['already_posted'] > 0 && ! $force) {
            $build['error'] = "{$build['already_posted']} invoice(s) of {$subject} are already posted in Sage "
                ."(e.g. {$build['already_posted_example']}). Posting again would double-bill; force only if you are certain.";

            return $build;
        }
        if ($build['docs'] === []) {
            $build['error'] = 'No postable documents (see unresolved); nothing written.';

            return $build;
        }

        $cfg = config('sage.posting');
        $conn = DB::connection(self::CONN);
        $startedAt = now()->format('Y-m-d H:i:s');

        $conn->transaction(function () use (&$build, $conn, $cfg, $startedAt): void {
            // Allocate number ranges under lock so concurrent Evolution users
            // cannot be handed the same invoice or audit number.
            $stdf = $conn->selectOne('SELECT idStDfTbl, InvPref, InvNum, InvPad FROM StDfTbl WITH (UPDLOCK) ORDER BY idStDfTbl');
            $entity = $conn->selectOne('SELECT TOP 1 iNextAuditNumber FROM Entities WITH (UPDLOCK)');
            $auditFloor = (int) $conn->selectOne(
                "SELECT ISNULL(MAX(TRY_CAST(LEFT(cAuditNumber, NULLIF(CHARINDEX('.', cAuditNumber), 0) - 1) AS int)), 0) AS n FROM PostGL"
            )->n;

            $taken = $conn->table('InvNum')->where('InvNumber', 'like', $stdf->InvPref.'%')
                ->pluck('InvNumber')->flip()->all();

            $seq = (int) $stdf->InvNum;
            $audit = max((int) $entity->iNextAuditNumber, $auditFloor) + 1;
            $now = now()->format('Y-m-d H:i:s');

            $postAr = [];
            $postGl = [];
            $details = [];
            $clientDeltas = [];
            $firstInv = null;
            $lastInv = null;

            foreach ($build['docs'] as $doc) {
                do {
                    $invNumber = $stdf->InvPref.str_pad((string) $seq++, (int) $stdf->InvPad, '0', STR_PAD_LEFT);
                } while (isset($taken[$invNumber]));
                $firstInv ??= $invNumber;
                $lastInv = $invNumber;
                $auditNumber = $audit++.'.0001';

                $header = $doc['header'];
                $header['InvNumber'] = $invNumber;
                $docId = (int) $conn->table('InvNum')->insertGetId($header, 'AutoIndex');

                $line = $doc['line'];
                $line['iInvoiceID'] = $docId;
                $lineId = (int) $conn->table('_btblInvoiceLines')->insertGetId($line, 'idInvoiceLines');

                $details[] = [
                    'iLDInvoiceID' => $docId, 'iLDInvoiceLineID' => $lineId, 'iLDInvoiceLineMatrixID' => 1,
                    'bMatrixEntry' => 0, 'iLotID' => 0, 'cLotNumber' => '', 'iStockBinLocationID' => 0,
                    'iUnitsOfMeasureID' => 0, 'iAttributeGroupID' => 0,
                    'fldQty' => 1, 'fldQtyToProcess' => 0, 'fldQtyReserved' => 0,
                    'fldQtyLastProcess' => 1, 'fldQtyProcessed' => 1,
                    '_btblInvoiceLineDetails_iBranchID' => 0,
                ];

                $ar = $doc['post_ar'];
                $ar['Reference'] = $invNumber;
                $ar['cAuditNumber'] = $auditNumber;
                $ar['InvNumKey'] = $docId;
                $ar['DTStamp'] = $now;
                $postAr[] = $ar;

                foreach ($doc['post_gl'] as $gl) {
                    $gl['Reference'] = $invNumber;
                    $gl['cAuditNumber'] = $auditNumber;
                    $gl['DTStamp'] = $now;
                    $postGl[] = $gl;
                }

                $d = &$clientDeltas[$doc['dclink']];
                $d ??= ['home' => 0.0, 'usd' => 0.0];
                $d['home'] = round($d['home'] + $doc['incl_home'], 2);
                $d['usd'] = round($d['usd'] + $doc['incl_usd'], 2);
                unset($d);
            }

            foreach ([['_btblInvoiceLineDetails', $details], ['PostAR', $postAr], ['PostGL', $postGl]] as [$table, $rows]) {
                // SQL Server allows at most 2,100 bound parameters per statement.
                $chunkSize = max(1, intdiv(2000, count($rows[0])));
                foreach (array_chunk($rows, $chunkSize) as $chunk) {
                    $conn->table($table)->insert($chunk);
                }
            }

            // Counters: StDfTbl holds the next unused number, Entities the last used.
            $conn->table('StDfTbl')->where('idStDfTbl', $stdf->idStDfTbl)->update(['InvNum' => (string) $seq]);
            $conn->table('Entities')->update(['iNextAuditNumber' => $audit - 1]);

            // Running client balances (home + foreign), as Evolution maintains them.
            foreach ($clientDeltas as $dclink => $delta) {
                $conn->table('Client')->where('DCLink', $dclink)->update([
                    'DCBalance' => DB::raw('DCBalance + '.sprintf('%.2F', $delta['home'])),
                    'fForeignBalance' => DB::raw('fForeignBalance + '.sprintf('%.2F', $delta['usd'])),
                    'dTimeStamp' => $now,
                ]);
            }

            // Ledger assertion: every audit set we just wrote must balance to the
            // cent, or the whole posting rolls back.
            $unbalanced = (int) $conn->selectOne(
                'SELECT COUNT(*) AS n FROM (SELECT cAuditNumber FROM PostGL WHERE UserName = ? AND DTStamp >= ? '
                .'GROUP BY cAuditNumber HAVING ABS(SUM(Debit) - SUM(Credit)) > 0.005) x',
                [$cfg['username'], $startedAt],
            )->n;
            if ($unbalanced > 0) {
                throw new \RuntimeException("Posting aborted: {$unbalanced} audit set(s) did not balance — rolled back.");
            }

            $build['posted'] = count($build['docs']);
            $build['invoice_from'] = $firstInv;
            $build['invoice_to'] = $lastInv;
        });

        return $build;
    }

    /** @return Collection<int, Invoice> */
    private function runInvoices(BillingRun $run): Collection
    {
        return Invoice::withoutGlobalScopes()
            ->where('billing_run_id', $run->id)
            ->with(['customer', 'lines.service.serviceType'])
            ->get()
            ->toBase();
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @return array<string,mixed>
     */
    private function build(Collection $invoices, Carbon $periodMonth): array
    {
        $database = (string) config('database.connections.'.self::CONN.'.database');
        $cfg = config('sage.posting');
        $conn = DB::connection(self::CONN);

        // --- Posting environment, resolved live from Sage.
        $stdf = $conn->table('StDfTbl')->orderBy('idStDfTbl')
            ->select('InvPref', 'InvNum', 'InvPad', 'InvTrCodeID')->first();
        if ($stdf === null) {
            throw new \RuntimeException("Inventory defaults (StDfTbl) not found in {$database}.");
        }

        $trCode = $conn->table('TrCodes')->where('idTrCodes', $stdf->InvTrCodeID)
            ->select('idTrCodes', 'Code', 'TaxAccountLink')->first();
        if ($trCode === null) {
            throw new \RuntimeException("Invoice transaction code #{$stdf->InvTrCodeID} not found in {$database}.");
        }

        $txDate = $periodMonth->format('Y-m-d');
        $period = $conn->table('_etblPeriod')
            ->whereBetween('dPeriodDate', [
                $periodMonth->copy()->startOfMonth()->format('Y-m-d'),
                $periodMonth->copy()->endOfMonth()->format('Y-m-d 23:59:59'),
            ])->first();
        if ($period === null) {
            throw new \RuntimeException("No Sage accounting period covers {$txDate} — create the period in Evolution first.");
        }
        if ((bool) $period->bBlocked) {
            throw new \RuntimeException("Sage accounting period {$period->idPeriod} ({$txDate}) is blocked for posting.");
        }

        $rate = (float) ($conn->table('CurrencyHist')
            ->where('iCurrencyID', $cfg['currency_id'])
            ->orderByDesc('dRateDate')->value('fSellRate') ?? 1.0);

        $taxRates = $conn->table('TaxRate')->pluck('TaxRate', 'idTaxRate');

        // Client classes: debtors control + VAT control per class.
        $classes = $conn->table('CliClass')
            ->select('IdCliClass', 'Code', 'iAccountsIDControlAcc', 'iTaxControlAccID')
            ->get()->keyBy('IdCliClass');

        // The Sage service item billed per class → revenue account via its group.
        $items = [];
        $itemRows = $conn->table('StkItem as s')
            ->join('_etblStockDetails as d', 'd.StockID', '=', 's.StockLink')
            ->join('GrpTbl as g', 'g.idGrpTbl', '=', 'd.GroupID')
            ->whereIn('s.Code', array_values(array_unique($cfg['class_items'])))
            ->select('s.StockLink', 's.Code', 's.Description_1', 'g.SalesAccLink')
            ->get()->keyBy('Code');
        foreach ($cfg['class_items'] as $classId => $itemCode) {
            $items[$classId] = $itemRows[$itemCode] ?? null;
        }

        // Debtor accounts: {stand}-{token}-… → client row (first wins on duplicates).
        $clients = [];
        foreach ($conn->table('Client')->select('DCLink', 'Account', 'iClassID', 'RepID', 'iARPriceListNameID')->cursor() as $c) {
            $parts = explode('-', (string) $c->Account);
            if (count($parts) < 2) {
                continue;
            }
            $key = trim($parts[0]).'|'.strtoupper(trim($parts[1]));
            $clients[$key] ??= $c;
        }

        // --- One Sage invoice document per O-Billing invoice line.
        $docs = [];
        $unresolved = [];
        $byToken = [];
        $obNumbers = [];

        foreach ($invoices as $invoice) {
            if ($invoice->currency !== 'USD') {
                $unresolved[] = "{$invoice->invoice_number}: currency {$invoice->currency} is not USD";

                continue;
            }
            $stand = $invoice->customer->account_number;

            foreach ($invoice->lines as $line) {
                $typeCode = (string) ($line->service?->serviceType?->code ?? '');
                if (! str_starts_with($typeCode, 'LEDGER-')) {
                    $unresolved[] = "{$invoice->invoice_number}: {$line->description} — not a ledger service";

                    continue;
                }
                $token = substr($typeCode, 7);

                $client = $clients[$stand.'|'.$token] ?? null;
                if ($client === null) {
                    $unresolved[] = "{$invoice->invoice_number}: no Sage account {$stand}-{$token}-…";

                    continue;
                }
                $class = $classes[(int) $client->iClassID] ?? null;
                if ($class === null || (int) $class->iAccountsIDControlAcc === 0) {
                    $unresolved[] = "{$invoice->invoice_number}: Sage client {$client->Account} has no class/control account";

                    continue;
                }
                $item = $items[(int) $client->iClassID] ?? null;
                if ($item === null || (int) $item->SalesAccLink === 0) {
                    $unresolved[] = "{$invoice->invoice_number}: no service item mapped for class {$class->Code} (see sage.posting.class_items)";

                    continue;
                }

                // Home (ZWG) amounts: tax = incl − excl so the entry balances exactly.
                $exclU = round((float) $line->amount, 2);
                $taxU = round((float) $line->tax_amount, 2);
                $inclU = round($exclU + $taxU, 2);
                $exclH = round($exclU * $rate, 2);
                $inclH = round($inclU * $rate, 2);
                $taxH = round($inclH - $exclH, 2);

                $taxed = $taxU > 0.0;
                $taxTypeId = $taxed ? (int) $cfg['tax_type_id'] : (int) $cfg['exempt_tax_type_id'];
                $taxPct = (float) ($taxRates[$taxTypeId] ?? 0.0);
                $controlAcc = (int) $class->iAccountsIDControlAcc;
                $taxCtrlAcc = (int) $class->iTaxControlAccID;
                $dclink = (int) $client->DCLink;
                $repId = (int) ($client->RepID ?? 0);

                $desc = mb_substr($line->service->serviceType->name.' '.$periodMonth->format('M Y'), 0, 50);
                $obNumbers[] = $invoice->invoice_number;

                $docs[] = [
                    'dclink' => $dclink,
                    'incl_home' => $inclH,
                    'incl_usd' => $inclU,
                    'header' => $this->documentHeader(
                        $cfg, $dclink, $repId, mb_substr((string) $invoice->customer->name, 0, 50),
                        $desc, $txDate, $rate, $invoice->invoice_number,
                        $exclH, $taxH, $inclH, $exclU, $taxU, $inclU,
                    ),
                    'line' => $this->documentLine(
                        $item, $taxTypeId, $taxPct, (int) ($client->iARPriceListNameID ?? 0), $repId,
                        $desc, $txDate, $exclH, $taxH, $inclH, $exclU, $taxU, $inclU,
                    ),
                    'post_ar' => [
                        'TxDate' => $txDate, 'Id' => 'Inv', 'AccountLink' => $dclink,
                        'TrCodeID' => (int) $trCode->idTrCodes, 'Debit' => $inclH, 'Credit' => 0.0,
                        'iCurrencyID' => (int) $cfg['currency_id'], 'fExchangeRate' => $rate,
                        'fForeignDebit' => $inclU, 'fForeignCredit' => 0.0,
                        'Description' => $desc, 'TaxTypeID' => 0,
                        'Order_No' => '', 'ExtOrderNum' => $invoice->invoice_number,
                        'Tax_Amount' => $taxH, 'fForeignTax' => $taxU, 'Project' => 0,
                        'Outstanding' => $inclH, 'fForeignOutstanding' => $inclU,
                        'RepID' => $repId, 'LinkAccCode' => 0, 'TillID' => 0,
                        'UserName' => $cfg['username'], 'cReference2' => '',
                        'fJCRepCost' => 0.0, 'iPostSettlementTermsID' => 0, 'iTxBranchID' => 0,
                        'iMBPropertyID' => 0, 'iMBPortionID' => 0, 'iMBServiceID' => 0,
                        'iMBMeterID' => 0, 'iMBPropertyPortionServiceID' => 0, 'bPBTPaid' => 0,
                        'iGLTaxAccountID' => $taxed ? $taxCtrlAcc : 0, 'iTransactionType' => 0,
                        'PostAR_iBranchID' => 0, 'iMajorIndustryCodeID' => 0, 'iTaxBadDebtState' => 0,
                    ],
                    'post_gl' => $this->glRows(
                        $cfg, (int) $trCode->idTrCodes, (int) ($trCode->TaxAccountLink ?? 0),
                        $txDate, (int) $period->idPeriod, $dclink, $desc,
                        $controlAcc, $taxCtrlAcc, (int) $item->SalesAccLink,
                        $taxed, $exclH, $taxH, $inclH,
                    ),
                ];

                $t = &$byToken[$token];
                $t ??= ['lines' => 0, 'usd_excl' => 0.0, 'usd_tax' => 0.0, 'usd_incl' => 0.0, 'revenue_account' => (int) $item->SalesAccLink];
                $t['lines']++;
                $t['usd_excl'] += $exclU;
                $t['usd_tax'] += $taxU;
                $t['usd_incl'] += $inclU;
                unset($t);
            }
        }

        // --- Double-post guard: any of this run's invoice numbers already on a
        // Sage document means the run (or part of it) was posted before.
        $alreadyPosted = 0;
        $example = null;
        foreach (array_chunk($obNumbers, 500) as $chunk) {
            $hits = $conn->table('InvNum')->whereIn('ExtOrderNum', $chunk)->pluck('ExtOrderNum');
            $alreadyPosted += $hits->count();
            $example ??= $hits->first();
        }

        return [
            'database' => $database,
            'tr_code' => $trCode->Code.' (#'.$trCode->idTrCodes.')',
            'exchange_rate' => $rate,
            'tx_date' => $txDate,
            'period_id' => (int) $period->idPeriod,
            'next_invoice_number' => $stdf->InvPref.str_pad((string) $stdf->InvNum, (int) $stdf->InvPad, '0', STR_PAD_LEFT),
            'docs' => $docs,
            'by_token' => $byToken,
            'unresolved' => $unresolved,
            'already_posted' => $alreadyPosted,
            'already_posted_example' => $example,
        ];
    }

    /** The InvNum document header, shaped exactly like an Evolution-posted invoice. */
    private function documentHeader(
        array $cfg, int $dclink, int $repId, string $accountName, string $desc, string $txDate,
        float $rate, string $obNumber,
        float $exclH, float $taxH, float $inclH, float $exclU, float $taxU, float $inclU,
    ): array {
        return [
            'DocType' => 0, 'DocVersion' => 7, 'DocState' => 4, 'DocFlag' => 0, 'OrigDocID' => 0,
            'GrvNumber' => '', 'GrvID' => 0,
            'AccountID' => $dclink, 'Description' => $desc,
            'InvDate' => $txDate, 'OrderDate' => $txDate, 'DueDate' => $txDate, 'DeliveryDate' => $txDate,
            'TaxInclusive' => 1, 'Email_Sent' => 0,
            'Address1' => '', 'Address2' => '', 'Address3' => '', 'Address4' => '', 'Address5' => '', 'Address6' => '',
            'PAddress1' => '', 'PAddress2' => '', 'PAddress3' => '', 'PAddress4' => '', 'PAddress5' => '', 'PAddress6' => '',
            'DelMethodID' => 0, 'DocRepID' => $repId, 'OrderNum' => '', 'DeliveryNote' => '',
            'InvDisc' => 0, 'Message1' => '', 'Message2' => '', 'Message3' => '',
            'ProjectID' => 0, 'TillID' => 0, 'POSAmntTendered' => 0, 'POSChange' => 0,
            'GrvSplitFixedCost' => 0, 'GrvSplitFixedAmnt' => 0,
            'OrderStatusID' => 0, 'OrderPriorityID' => 0, 'ExtOrderNum' => $obNumber,
            'ForeignCurrencyID' => (int) $cfg['currency_id'],
            'InvDiscAmnt' => 0, 'InvDiscAmntEx' => 0,
            'InvTotExclDEx' => $exclH, 'InvTotTaxDEx' => $taxH, 'InvTotInclDEx' => $inclH,
            'InvTotExcl' => $exclH, 'InvTotTax' => $taxH, 'InvTotIncl' => $inclH,
            'OrdDiscAmnt' => 0, 'OrdDiscAmntEx' => 0,
            'OrdTotExclDEx' => $exclH, 'OrdTotTaxDEx' => $taxH, 'OrdTotInclDEx' => $inclH,
            'OrdTotExcl' => $exclH, 'OrdTotTax' => $taxH, 'OrdTotIncl' => $inclH,
            'bUseFixedPrices' => 0, 'iINVNUMAgentID' => (int) $cfg['agent_id'], 'fExchangeRate' => $rate,
            'fGrvSplitFixedAmntForeign' => 0, 'fInvDiscAmntForeign' => 0, 'fInvDiscAmntExForeign' => 0,
            'fInvTotExclDExForeign' => $exclU, 'fInvTotTaxDExForeign' => $taxU, 'fInvTotInclDExForeign' => $inclU,
            'fInvTotExclForeign' => $exclU, 'fInvTotTaxForeign' => $taxU, 'fInvTotInclForeign' => $inclU,
            'fOrdDiscAmntForeign' => 0, 'fOrdDiscAmntExForeign' => 0,
            'fOrdTotExclDExForeign' => $exclU, 'fOrdTotTaxDExForeign' => $taxU, 'fOrdTotInclDExForeign' => $inclU,
            'fOrdTotExclForeign' => $exclU, 'fOrdTotTaxForeign' => $taxU, 'fOrdTotInclForeign' => $inclU,
            'cTaxNumber' => '', 'cAccountName' => $accountName,
            'iProspectID' => 0, 'iOpportunityID' => 0,
            'InvTotRounding' => 0, 'OrdTotRounding' => 0,
            'fInvTotForeignRounding' => 0, 'fOrdTotForeignRounding' => 0,
            'bInvRounding' => 1, 'iInvSettlementTermsID' => 0, 'iOrderCancelReasonID' => 0,
            'iLinkedDocID' => 0, 'bLinkedTemplate' => 0,
            'InvTotInclExRounding' => $inclH, 'OrdTotInclExRounding' => $inclH,
            'fInvTotInclForeignExRounding' => $inclU, 'fOrdTotInclForeignExRounding' => $inclU,
            'iEUNoTCID' => 0, 'iPOAuthStatus' => 0, 'iPOIncidentID' => 0, 'iMergedDocID' => 0,
            'iDocEmailed' => 0, 'bTaxPerLine' => 1,
            'fDepositAmountTotal' => 0, 'fDepositAmountUnallocated' => 0, 'fDepositAmountNew' => 0,
            'cContact' => '', 'cTelephone' => '', 'cFax' => '', 'cEmail' => '', 'cCellular' => '',
            'iInsuranceState' => 0, 'cAuthorisedBy' => '', 'cClaimNumber' => '', 'cPolicyNumber' => '',
            'cExcessAccName' => '', 'cExcessAccCont1' => '', 'cExcessAccCont2' => '',
            'fExcessAmt' => 0, 'fExcessPct' => 0, 'fExcessExclusive' => 0, 'fExcessInclusive' => 0, 'fExcessTax' => 0,
            'fAddChargeExclusive' => 0, 'fAddChargeTax' => 0, 'fAddChargeInclusive' => 0,
            'fAddChargeExclusiveForeign' => 0, 'fAddChargeTaxForeign' => 0, 'fAddChargeInclusiveForeign' => 0,
            'fOrdAddChargeExclusive' => 0, 'fOrdAddChargeTax' => 0, 'fOrdAddChargeInclusive' => 0,
            'fOrdAddChargeExclusiveForeign' => 0, 'fOrdAddChargeTaxForeign' => 0, 'fOrdAddChargeInclusiveForeign' => 0,
            'iInvoiceSplitDocID' => 0, 'cGIVNumber' => '', 'bIsDCOrder' => 0,
            'iDCBranchID' => 0, 'iSalesBranchID' => 0, 'InvNum_iBranchID' => 0,
            'bIDFProccessed' => 0, 'bSBSI' => 0, 'iImportDeclarationID' => 0,
            'cPermitNumber' => '', 'iStateID' => 0, 'cQuoteNum' => '',
        ];
    }

    /** The single _btblInvoiceLines row for a document (qty 1, fully processed). */
    private function documentLine(
        object $item, int $taxTypeId, float $taxPct, int $priceListId, int $repId,
        string $desc, string $txDate,
        float $exclH, float $taxH, float $inclH, float $exclU, float $taxU, float $inclU,
    ): array {
        return [
            'iOrigLineID' => 0, 'iGrvLineID' => 0,
            'cDescription' => $desc,
            'iUnitsOfMeasureStockingID' => 0, 'iUnitsOfMeasureCategoryID' => 0, 'iUnitsOfMeasureID' => 0,
            'fQuantity' => 1, 'fQtyChange' => 0, 'fQtyToProcess' => 0,
            'fQtyLastProcess' => 1, 'fQtyProcessed' => 1,
            'fQtyReserved' => 0, 'fQtyReservedChange' => 0, 'cLineNotes' => '',
            'fUnitPriceExcl' => $exclH, 'fUnitPriceIncl' => $inclH, 'fUnitCost' => 0, 'fLineDiscount' => 0,
            'fTaxRate' => $taxPct, 'bIsSerialItem' => 0, 'bIsWhseItem' => 0, 'fAddCost' => 0,
            'iStockCodeID' => (int) $item->StockLink, 'iJobID' => 0, 'iWarehouseID' => 0,
            'iTaxTypeID' => $taxTypeId, 'iPriceListNameID' => $priceListId,
            'fQuantityLineTotIncl' => $inclH, 'fQuantityLineTotExcl' => $exclH,
            'fQuantityLineTotInclNoDisc' => $inclH, 'fQuantityLineTotExclNoDisc' => $exclH,
            'fQuantityLineTaxAmount' => $taxH, 'fQuantityLineTaxAmountNoDisc' => $taxH,
            'fQtyChangeLineTotIncl' => 0, 'fQtyChangeLineTotExcl' => 0,
            'fQtyChangeLineTotInclNoDisc' => 0, 'fQtyChangeLineTotExclNoDisc' => 0,
            'fQtyChangeLineTaxAmount' => 0, 'fQtyChangeLineTaxAmountNoDisc' => 0,
            'fQtyToProcessLineTotIncl' => 0, 'fQtyToProcessLineTotExcl' => 0,
            'fQtyToProcessLineTotInclNoDisc' => 0, 'fQtyToProcessLineTotExclNoDisc' => 0,
            'fQtyToProcessLineTaxAmount' => 0, 'fQtyToProcessLineTaxAmountNoDisc' => 0,
            'fQtyLastProcessLineTotIncl' => $inclH, 'fQtyLastProcessLineTotExcl' => $exclH,
            'fQtyLastProcessLineTotInclNoDisc' => $inclH, 'fQtyLastProcessLineTotExclNoDisc' => $exclH,
            'fQtyLastProcessLineTaxAmount' => $taxH, 'fQtyLastProcessLineTaxAmountNoDisc' => $taxH,
            'fQtyProcessedLineTotIncl' => $inclH, 'fQtyProcessedLineTotExcl' => $exclH,
            'fQtyProcessedLineTotInclNoDisc' => $inclH, 'fQtyProcessedLineTotExclNoDisc' => $exclH,
            'fQtyProcessedLineTaxAmount' => $taxH, 'fQtyProcessedLineTaxAmountNoDisc' => $taxH,
            'fUnitPriceExclForeign' => $exclU, 'fUnitPriceInclForeign' => $inclU,
            'fQuantityLineTotInclForeign' => $inclU, 'fQuantityLineTotExclForeign' => $exclU,
            'fQuantityLineTotInclNoDiscForeign' => $inclU, 'fQuantityLineTotExclNoDiscForeign' => $exclU,
            'fQuantityLineTaxAmountForeign' => $taxU, 'fQuantityLineTaxAmountNoDiscForeign' => $taxU,
            'fQtyChangeLineTotInclForeign' => 0, 'fQtyChangeLineTotExclForeign' => 0,
            'fQtyChangeLineTotInclNoDiscForeign' => 0, 'fQtyChangeLineTotExclNoDiscForeign' => 0,
            'fQtyChangeLineTaxAmountForeign' => 0, 'fQtyChangeLineTaxAmountNoDiscForeign' => 0,
            'fQtyToProcessLineTotInclForeign' => 0, 'fQtyToProcessLineTotExclForeign' => 0,
            'fQtyToProcessLineTotInclNoDiscForeign' => 0, 'fQtyToProcessLineTotExclNoDiscForeign' => 0,
            'fQtyToProcessLineTaxAmountForeign' => 0, 'fQtyToProcessLineTaxAmountNoDiscForeign' => 0,
            'fQtyLastProcessLineTotInclForeign' => $inclU, 'fQtyLastProcessLineTotExclForeign' => $exclU,
            'fQtyLastProcessLineTotInclNoDiscForeign' => $inclU, 'fQtyLastProcessLineTotExclNoDiscForeign' => $exclU,
            'fQtyLastProcessLineTaxAmountForeign' => $taxU, 'fQtyLastProcessLineTaxAmountNoDiscForeign' => $taxU,
            'fQtyProcessedLineTotInclForeign' => $inclU, 'fQtyProcessedLineTotExclForeign' => $exclU,
            'fQtyProcessedLineTotInclNoDiscForeign' => $inclU, 'fQtyProcessedLineTotExclNoDiscForeign' => $exclU,
            'fQtyProcessedLineTaxAmountForeign' => $taxU, 'fQtyProcessedLineTaxAmountNoDiscForeign' => $taxU,
            'iLineRepID' => $repId, 'iLineProjectID' => 0, 'iLedgerAccountID' => 0, 'iModule' => 0,
            'bChargeCom' => 0, 'bIsLotItem' => 0, 'iMFPID' => 0, 'iLineID' => 1, 'iLinkedLineID' => 0,
            'iDeliveryMethodID' => 0, 'fQtyDeliver' => 0, 'dDeliveryDate' => $txDate,
            'iDeliveryStatus' => 0, 'fQtyForDelivery' => 1,
            'bPromotionApplied' => 0, 'fPromotionPriceExcl' => 0, 'fPromotionPriceIncl' => 0, 'cPromotionCode' => '',
            'iSOLinkedPOLineID' => 0, 'fLength' => 0, 'fWidth' => 0, 'fHeight' => 0,
            'iPieces' => 0, 'iPiecesToProcess' => 0, 'iPiecesLastProcess' => 0, 'iPiecesProcessed' => 0,
            'iPiecesReserved' => 0, 'iPiecesDeliver' => 0, 'iPiecesForDelivery' => 0,
            'fQuantityUR' => 1, 'fQtyChangeUR' => 0, 'fQtyToProcessUR' => 0,
            'fQtyLastProcessUR' => 1, 'fQtyProcessedUR' => 1,
            'fQtyReservedUR' => 0, 'fQtyReservedChangeUR' => 0,
            'fQtyDeliverUR' => 0, 'fQtyForDeliveryUR' => 1,
            'iSalesWhseID' => 0, '_btblInvoiceLines_iBranchID' => 0, 'iMajorIndustryCodeID' => 0,
            'bReverseChargeApplied' => 0, 'fRecommendedRetailPrice' => 0, 'iSelectedBarcodeID' => 0,
        ];
    }

    /**
     * The balanced PostGL double entry for one document, home currency:
     * DR debtors control (incl) / CR VAT (tax, taxed lines only) / CR revenue (excl).
     *
     * @return list<array<string,mixed>>
     */
    private function glRows(
        array $cfg, int $trCodeId, int $trCodeTaxAcc,
        string $txDate, int $periodId, int $dclink, string $desc,
        int $controlAcc, int $taxCtrlAcc, int $salesAcc,
        bool $taxed, float $exclH, float $taxH, float $inclH,
    ): array {
        // Multi-row inserts over pdo_sqlsrv require a consistent PHP type per
        // column across rows (mixing int 0 with a float in the same column makes
        // the driver coerce the float into an int parameter and fail), so every
        // float column is a float in every row.
        $common = [
            'TxDate' => $txDate, 'TrCodeID' => $trCodeId,
            'iCurrencyID' => 0, 'fExchangeRate' => 0.0, 'fForeignDebit' => 0.0, 'fForeignCredit' => 0.0,
            'Description' => $desc, 'TaxTypeID' => 0, 'Order_No' => '', 'ExtOrderNum' => '',
            'fForeignTax' => 0.0, 'Project' => 0, 'Period' => $periodId, 'DrCrAccount' => $dclink,
            'JobCodeLink' => 0, 'UserName' => $cfg['username'], 'cPayeeName' => '', 'bPrintCheque' => 0,
            'cReference2' => '', 'RepID' => 0, 'fJCRepCost' => 0.0, 'iMFPID' => 0,
            'bIsJCDocLine' => 0, 'bIsSTGLDocLine' => 0, 'iInvLineID' => 0, 'iTxBranchID' => 0,
            'cBankRef' => '', 'bPBTPaid' => 0, 'bReconciled' => 0,
            'PostGL_iBranchID' => 0, 'iMajorIndustryCodeID' => 0, 'iImportDeclarationID' => 0,
        ];

        $rows = [];
        $rows[] = ['Id' => 'Inv', 'AccountLink' => $controlAcc, 'Debit' => $inclH, 'Credit' => 0.0,
            'Tax_Amount' => $taxH, 'iGLTaxAccountID' => $taxed ? $taxCtrlAcc : 0] + $common;
        if ($taxed && $taxH > 0.0) {
            $rows[] = ['Id' => 'TaxAR', 'AccountLink' => $taxCtrlAcc, 'Debit' => 0.0, 'Credit' => $taxH,
                'Tax_Amount' => 0.0, 'iGLTaxAccountID' => 0] + $common;
        }
        $rows[] = ['Id' => 'Inv', 'AccountLink' => $salesAcc, 'Debit' => 0.0, 'Credit' => round($inclH - ($taxed ? $taxH : 0.0), 2),
            'Tax_Amount' => $taxed ? $taxH : 0.0, 'iGLTaxAccountID' => $taxed ? $trCodeTaxAcc : 0] + $common;

        return $rows;
    }
}
