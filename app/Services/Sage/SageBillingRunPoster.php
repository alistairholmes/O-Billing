<?php

declare(strict_types=1);

namespace App\Services\Sage;

use App\Models\BillingRun;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * Stages a completed O-Billing billing run in Sage Evolution as an UNPOSTED
 * debtors batch (`_etblARAPBatches` + `_etblARAPBatchLines`) on the
 * `sage_write` connection.
 *
 * Deliberately does NOT touch PostAR/PostGL/InvNum: a Sage operator reviews
 * the staged batch inside Evolution (Debtors → Batch Entry) and posts it
 * there, so Sage's own posting engine writes the double entry — debit the
 * stand's debtor account, credit the service's revenue account, output VAT to
 * the tax account — and the trial balance can never be left unbalanced by us.
 *
 * Line construction:
 *   • debtor  = the stand's per-service Sage account ({stand}-{token}-…)
 *   • tr code = config sage.posting.invoice_tr_code (e.g. IN-P1SP4)
 *   • contra  = config sage.posting.revenue_accounts[token] (GL account code);
 *               unmapped tokens are staged without a contra and reported, so
 *               the operator fills them in the batch grid before posting
 *   • amounts = USD (the debtor currency) in the foreign columns, converted
 *               to home currency (ZWG) at the latest CurrencyHist rate
 *   • tax     = lines carrying tax use the configured output-tax type and the
 *               tr code's tax account
 *
 * `preview()` builds everything without writing; `post()` stages the batch.
 */
final class SageBillingRunPoster
{
    private const CONN = 'sage_write';

    /** @return array<string,mixed> */
    public function preview(BillingRun $run): array
    {
        return $this->build($run);
    }

    /** @return array<string,mixed> */
    public function post(BillingRun $run, bool $force = false): array
    {
        $build = $this->build($run);

        if ($build['already_staged'] && ! $force) {
            $build['error'] = "A Sage batch for run {$run->run_number} already exists (ref {$build['batch_ref']}). Post or delete it in Sage, or pass --force to stage another.";

            return $build;
        }
        if ($build['lines'] === []) {
            $build['error'] = 'No stageable lines (see unresolved); nothing written.';

            return $build;
        }

        DB::connection(self::CONN)->transaction(function () use (&$build): void {
            $batchId = (int) DB::connection(self::CONN)->table('_etblARAPBatches')->insertGetId($build['header']);

            $n = 0;
            $rows = [];
            foreach ($build['lines'] as $line) {
                $line['iBatchID'] = $batchId;
                $line['idLinePermanent'] = ++$n;
                $rows[] = $line;
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::connection(self::CONN)->table('_etblARAPBatchLines')->insert($chunk);
            }

            $build['batch_id'] = $batchId;
            $build['staged'] = count($rows);
        });

        return $build;
    }

    /** @return array<string,mixed> */
    private function build(BillingRun $run): array
    {
        $database = (string) config('database.connections.'.self::CONN.'.database');
        $cfg = config('sage.posting');

        // --- Sage-side lookups (all against the write target, so FKs are valid there).
        $trCode = DB::connection(self::CONN)->table('TrCodes')
            ->where('Code', $cfg['invoice_tr_code'])->where('iModule', 5)
            ->select('idTrCodes', 'Account1Link', 'TaxAccountLink', 'TaxTypeID')->first();
        if ($trCode === null) {
            throw new \RuntimeException("AR transaction code '{$cfg['invoice_tr_code']}' not found in {$database}.");
        }

        $revenueByToken = [];
        $codes = array_values(array_unique(array_filter($cfg['revenue_accounts'])));
        $accountLinks = DB::connection(self::CONN)->table('Accounts')
            ->whereIn('Account', $codes)->pluck('AccountLink', 'Account');
        foreach ($cfg['revenue_accounts'] as $token => $code) {
            $revenueByToken[$token] = isset($accountLinks[$code]) ? (int) $accountLinks[$code] : null;
        }

        $rate = (float) (DB::connection(self::CONN)->table('CurrencyHist')
            ->where('iCurrencyID', $cfg['currency_id'])
            ->orderByDesc('dRateDate')->value('fSellRate') ?? 1.0);

        // Debtor accounts: {stand}-{token}-… → DCLink (first wins on duplicates).
        $debtors = [];
        foreach (DB::connection(self::CONN)->table('Client')->select('Account', 'DCLink')->cursor() as $c) {
            $parts = explode('-', (string) $c->Account);
            if (count($parts) < 2) {
                continue;
            }
            $key = trim($parts[0]).'|'.strtoupper(trim($parts[1]));
            $debtors[$key] ??= (int) $c->DCLink;
        }

        // --- Build one batch line per O-Billing invoice line.
        $txDate = $run->period_month->format('Y-m-d');
        $lines = [];
        $unresolved = [];
        $byToken = [];

        $invoices = Invoice::withoutGlobalScopes()
            ->where('billing_run_id', $run->id)
            ->with(['customer', 'lines.service.serviceType'])
            ->get();

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

                $dclink = $debtors[$stand.'|'.$token] ?? null;
                if ($dclink === null) {
                    $unresolved[] = "{$invoice->invoice_number}: no Sage account {$stand}-{$token}-…";

                    continue;
                }

                $exclUsd = round((float) $line->amount, 2);
                $taxUsd = round((float) $line->tax_amount, 2);
                $inclUsd = round($exclUsd + $taxUsd, 2);
                $taxed = $taxUsd > 0.0;
                $contra = $revenueByToken[$token] ?? null;

                $lines[] = [
                    'dTxDate' => $txDate,
                    'iAccountID' => $dclink,
                    'iAccountCurrencyID' => (int) $cfg['currency_id'],
                    'iTrCodeID' => (int) $trCode->idTrCodes,
                    'iGLContraID' => $contra,
                    'bPostDated' => 0,
                    'cReference' => $invoice->invoice_number,
                    'cDescription' => mb_substr($line->service->serviceType->name.' '.$run->period_month->format('M Y'), 0, 50),
                    'cOrderNumber' => '',
                    'fAmountExcl' => round($exclUsd * $rate, 2),
                    'iTaxTypeID' => $taxed ? (int) $cfg['tax_type_id'] : 0,
                    'fAmountIncl' => round($inclUsd * $rate, 2),
                    'fExchangeRate' => $rate,
                    'fAmountExclForeign' => $exclUsd,
                    'fAmountInclForeign' => $inclUsd,
                    'fAccountExchangeRate' => $rate,
                    'fAccountForeignAmountExcl' => $exclUsd,
                    'fAccountForeignAmountIncl' => $inclUsd,
                    'iDiscGLContraID' => null,
                    'fDiscPercent' => 0,
                    'fDiscAmountExcl' => 0,
                    'iDiscTaxTypeID' => 0,
                    'fDiscAmountIncl' => 0,
                    'fDiscAmountExclForeign' => 0,
                    'fDiscAmountInclForeign' => 0,
                    'fAccountForeignDiscAmountExcl' => 0,
                    'fAccountForeignDiscAmountIncl' => 0,
                    'iProjectID' => 0,
                    'iSalesRepID' => 0,
                    'iBatchSettlementTermsID' => 0,
                    'iModule' => 0, // debtors
                    'iTaxAccountID' => $taxed ? (int) ($trCode->TaxAccountLink ?? 0) : 0,
                    'bIsDebit' => 1,
                ];

                $t = &$byToken[$token];
                $t ??= ['lines' => 0, 'usd_excl' => 0.0, 'usd_tax' => 0.0, 'usd_incl' => 0.0, 'contra' => $contra];
                $t['lines']++;
                $t['usd_excl'] += $exclUsd;
                $t['usd_tax'] += $taxUsd;
                $t['usd_incl'] += $inclUsd;
                unset($t);
            }
        }

        // --- Batch header: copy the council's own AR-batch conventions.
        $template = DB::connection(self::CONN)->table('_etblARAPBatches')
            ->where('iDCModule', 0)->orderBy('idARAPBatches')->first();

        $batchNo = config('sage.posting.batch_prefix').str_pad((string) $run->id, 8, '0', STR_PAD_LEFT);
        $header = [
            'iDCModule' => 0, // debtors
            'cBatchNo' => $batchNo,
            'cBatchDesc' => mb_substr('O-Billing '.$run->run_number, 0, 40),
            'cBatchRef' => mb_substr('OB-'.$run->run_number, 0, 20),
            'bClearAfterPost' => 1,
            'bAllowDupRef' => 1,
            'iCurrencyID' => (int) $cfg['currency_id'],
            'bCurrencySingle' => 1,
            'iAgentCreatorID' => (int) ($template->iAgentCreatorID ?? 0),
            'fValidateDebits' => 0,
            'fValidateCredits' => 0,
            'bShowGLContra' => 1,
            'bEditGLContra' => 1,
            'bAllowGLContraSplit' => 0,
            'bEnterTaxOnGlContraSplit' => 0,
            'bIncludeLinkedAccounts' => 0,
            'bEnterExclOnGlContraSplit' => 1,
            'bValidateOverTerms' => 0,
            'bValidateOverLimit' => 0,
            'bInterBranchBatch' => 0,
            'bModuleAR' => 1,
            'bModuleAP' => 0,
            'bModuleGL' => 0,
            'iInputTaxID' => 0,
            'iOutputTaxID' => (int) $cfg['tax_type_id'],
            'iOutputTaxAccID' => (int) ($template->iOutputTaxAccID ?? ($trCode->TaxAccountLink ?? 0)),
            'bCalcTax' => 0,
            'iDefaultModule' => 0,
        ];

        $alreadyStaged = DB::connection(self::CONN)->table('_etblARAPBatches')
            ->where('cBatchRef', $header['cBatchRef'])->exists();
        $previouslyPosted = DB::connection(self::CONN)->table('_etblARAPBatchHistory')
            ->where('cBatchReference', $header['cBatchRef'])->exists();

        return [
            'database' => $database,
            'batch_no' => $batchNo,
            'batch_ref' => $header['cBatchRef'],
            'tr_code' => $cfg['invoice_tr_code'].' (#'.$trCode->idTrCodes.')',
            'exchange_rate' => $rate,
            'tx_date' => $txDate,
            'header' => $header,
            'lines' => $lines,
            'by_token' => $byToken,
            'revenue_by_token' => $revenueByToken,
            'unresolved' => $unresolved,
            'already_staged' => $alreadyStaged,
            'previously_posted' => $previouslyPosted,
        ];
    }
}
