<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class AlphaPayService
{
    public function createCheckout(\App\Models\Order $order): string
    {
        $key = config('services.alphapay.key');
        if (! $key) {
            throw new RuntimeException('Clé AlphaPay absente.');
        }

        $response = Http::acceptJson()->asJson()->withToken($key)->timeout(20)
            ->post('https://api.alphapay.me/api/v1/checkout-sessions/', [
                'amount' => number_format($order->montant_acompte, 2, '.', ''),
                'currency' => 'XOF',
                'description' => 'Commande '.$order->reference,
                'country' => 'TG',
                'customer_email' => $order->user->email,
                'customer_name' => $order->user->name,
                'return_url' => route('orders.show', $order),
                'metadata' => ['order_reference' => $order->reference],
            ]);

        if (! $response->successful() || ! $response->json('checkout_url')) {
            throw new RuntimeException('Création session AlphaPay impossible.');
        }

        $order->update(['alphapay_session_id' => $response->json('id')]);

        return $response->json('checkout_url');
    }
}
