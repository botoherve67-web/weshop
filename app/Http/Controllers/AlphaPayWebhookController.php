<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AlphaPayWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        $raw = $request->getContent();
        $timestamp = $request->header('X-Webhook-Timestamp', '0');
        $signature = $request->header('X-Webhook-Signature', '');
        $secret = config('services.alphapay.webhook_secret');

        if (! $secret || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300
            || ! hash_equals(hash_hmac('sha256', $timestamp.'.'.$raw, $secret), $signature)) {
            abort(403);
        }

        $payload = json_decode($raw, true);
        $data = $payload['data'] ?? [];
        if (($payload['event'] ?? null) !== 'transaction.success') {
            return response()->json(['ok' => true]);
        }

        $reference = $data['metadata']['order_reference'] ?? $data['reference'] ?? null;
        $order = $reference ? Order::where('reference', $reference)->first() : null;
        if (! $order || $order->moyen_paiement !== 'alphapay') {
            Log::warning('Webhook AlphaPay sans commande reconnue.', ['reference' => $reference]);
            return response()->json(['ok' => true]);
        }

        if (($data['status'] ?? null) === 'SUCCESS' && (float) ($data['amount'] ?? 0) === (float) $order->montant_acompte
            && ($data['currency'] ?? null) === 'XOF' && $order->statut_paiement === 'en_attente') {
            $order->update([
                'statut_paiement' => $order->type === 'precommande' ? 'acompte_paye' : 'paye',
                'transaction_id' => $data['id'] ?? $data['reference'] ?? null,
            ]);
        }

        return response()->json(['ok' => true]);
    }
}
