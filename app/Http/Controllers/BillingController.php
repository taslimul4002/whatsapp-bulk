<?php

namespace App\Http\Controllers;

use App\Support\BillingService;
use App\Support\PayPalClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class BillingController extends Controller
{
    public function index(): Response
    {
        $purchaseBalances = DB::table('billing_purchases')
            ->join('credit_ledger', 'credit_ledger.purchase_id', '=', 'billing_purchases.id')
            ->whereIn('billing_purchases.status', ['paid', 'refund_pending', 'review', 'refunded'])
            ->groupBy('billing_purchases.id', 'billing_purchases.workspace_id', 'billing_purchases.status', 'billing_purchases.expires_at')
            ->selectRaw("billing_purchases.workspace_id, CASE WHEN billing_purchases.status = 'paid' AND (billing_purchases.expires_at IS NULL OR billing_purchases.expires_at > ?) THEN SUM(credit_ledger.credits) WHEN SUM(credit_ledger.credits) < 0 THEN SUM(credit_ledger.credits) ELSE 0 END as credits", [now()]);
        $grantBalances = DB::table('broadcast_credit_grants')
            ->join('billing_purchases', 'billing_purchases.id', '=', 'broadcast_credit_grants.purchase_id')
            ->groupBy('billing_purchases.workspace_id')
            ->selectRaw("billing_purchases.workspace_id, SUM(CASE WHEN billing_purchases.status = 'paid' OR broadcast_credit_grants.balance < 0 THEN broadcast_credit_grants.balance ELSE 0 END) as credits");

        return Inertia::render('BillingSettings', [
            'plans' => DB::table('billing_plans')->orderBy('id')->get(),
            'workspaces' => DB::table('workspaces')->orderBy('name')->get(['id', 'name']),
            'purchases' => DB::table('billing_purchases')->join('workspaces', 'workspaces.id', '=', 'billing_purchases.workspace_id')
                ->leftJoin('billing_invoices', 'billing_invoices.purchase_id', '=', 'billing_purchases.id')
                ->orderByDesc('billing_purchases.id')->paginate(50, [
                    'billing_purchases.*', 'workspaces.name as workspace_name', 'billing_invoices.number as invoice_number',
                ], 'purchases_page')->withQueryString(),
            'ledger' => DB::table('credit_ledger')->join('workspaces', 'workspaces.id', '=', 'credit_ledger.workspace_id')
                ->orderByDesc('credit_ledger.id')->paginate(50, ['credit_ledger.*', 'workspaces.name as workspace_name'], 'ledger_page')->withQueryString(),
            'transactions' => DB::table('billing_transactions')->orderByDesc('id')->paginate(50, ['*'], 'transactions_page')->withQueryString(),
            'balances' => DB::query()->fromSub($purchaseBalances->unionAll($grantBalances), 'balances')
                ->groupBy('workspace_id')->havingRaw('SUM(credits) <> 0')->selectRaw('workspace_id, SUM(credits) as credits')->get(),
        ]);
    }

    public function storePlan(Request $request): RedirectResponse
    {
        $data = $this->validatePlan($request);
        DB::table('billing_plans')->insert([...$data, 'created_at' => now(), 'updated_at' => now()]);

        return back();
    }

    public function updatePlan(Request $request, int $plan): RedirectResponse
    {
        $data = $this->validatePlan($request);
        abort_unless(DB::table('billing_plans')->where('id', $plan)->exists(), 404);
        DB::table('billing_plans')->where('id', $plan)->update([...$data, 'updated_at' => now()]);

        return back();
    }

    private function validatePlan(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'price_cents' => ['required', 'integer', 'min:1', 'max:99999999'],
            'credits' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'expiry_days' => ['nullable', 'integer', 'min:1', 'max:36500'],
            'active' => ['required', 'boolean'],
        ]);
    }

    public function purchase(Request $request, PayPalClient $paypal): \Symfony\Component\HttpFoundation\Response
    {
        $data = $request->validate([
            'workspace_id' => ['required', 'integer', 'exists:workspaces,id'],
            'plan_id' => ['required', 'integer', 'exists:billing_plans,id'],
        ]);
        $plan = DB::table('billing_plans')->where('id', $data['plan_id'])->where('active', true)->first();
        if (! $plan) {
            throw ValidationException::withMessages(['plan_id' => 'The selected plan is not active.']);
        }
        $id = DB::table('billing_purchases')->insertGetId([
            'workspace_id' => $data['workspace_id'], 'plan_id' => $plan->id, 'created_by' => $request->user()->id,
            'plan_name' => $plan->name, 'price_cents' => $plan->price_cents, 'credits' => $plan->credits,
            'expiry_days' => $plan->expiry_days, 'status' => 'creating', 'request_id' => (string) Str::uuid(),
            'capture_request_id' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->checkout($id, $paypal);
    }

    public function checkout(int $purchase, PayPalClient $paypal): \Symfony\Component\HttpFoundation\Response
    {
        $row = DB::table('billing_purchases')->where('id', $purchase)->first();
        abort_unless($row, 404);
        abort_unless(in_array($row->status, ['creating', 'pending'], true), 409);
        $order = $paypal->createOrder($row);
        $id = $order['id'] ?? null;
        $links = $order['links'] ?? [];
        $approval = collect($links)->first(fn ($link) => in_array($link['rel'] ?? null, ['payer-action', 'approve'], true));
        $url = $approval['href'] ?? null;
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;
        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9-]{1,64}$/', $id)
            || ! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || ! in_array($host, ['www.paypal.com', 'www.sandbox.paypal.com'], true)
            || ($row->paypal_order_id !== null && $row->paypal_order_id !== $id)) {
            throw new RuntimeException('PayPal returned an invalid order.');
        }
        DB::table('billing_purchases')->where('id', $purchase)->where('status', 'creating')
            ->update(['paypal_order_id' => $id, 'status' => 'pending', 'updated_at' => now()]);

        return Inertia::location($url);
    }

    public function capture(Request $request, int $purchase, PayPalClient $paypal, BillingService $billing): RedirectResponse
    {
        $row = DB::table('billing_purchases')->where('id', $purchase)->first();
        abort_unless($row, 404);
        if ($row->status === 'paid') {
            return back();
        }
        abort_unless($row->status === 'pending' && $row->paypal_order_id, 409);
        $billing->recordCapture($purchase, $paypal->capture($row), $request->user()->id);

        return back();
    }

    public function refund(Request $request, int $purchase, PayPalClient $paypal, BillingService $billing): RedirectResponse
    {
        $row = DB::transaction(function () use ($purchase) {
            $row = DB::table('billing_purchases')->where('id', $purchase)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless(in_array($row->status, ['paid', 'refund_pending'], true), 409);
            $used = DB::table('credit_ledger')->where('purchase_id', $purchase)->whereNotIn('type', ['purchase', 'expiry'])->exists();
            abort_if($used, 409, 'Only unused purchases can be refunded.');
            if ($row->status === 'paid') {
                $row->refund_request_id = (string) Str::uuid();
                DB::table('billing_purchases')->where('id', $purchase)->update([
                    'status' => 'refund_pending', 'refund_request_id' => $row->refund_request_id, 'updated_at' => now(),
                ]);
            }

            return $row;
        });
        $refund = $paypal->refund($row);
        if (($refund['status'] ?? null) !== 'COMPLETED' || ! is_string($refund['id'] ?? null)
            || ! preg_match('/^[A-Za-z0-9-]{1,64}$/', $refund['id'])
            || ($refund['amount']['currency_code'] ?? null) !== 'USD'
            || ($refund['amount']['value'] ?? null) !== PayPalClient::amount($row->price_cents)) {
            throw new RuntimeException('PayPal refund is not confirmed. Retry or check PayPal.');
        }
        $billing->recordRefund($purchase, $refund['id'], $row->price_cents, false, $request->user()->id);

        return back();
    }
}
