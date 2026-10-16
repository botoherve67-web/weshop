<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\User;
use App\Services\AlphaPayService;
use App\Notifications\AdminOrderNotification;
use App\Notifications\OrderUpdateNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    // Frais de livraison fixes par zone (à ajuster depuis l'admin plus tard)
    protected array $fraisParZone = [
        'lome_centre' => 1000,
        'lome_peripherie' => 1500,
        'interieur_togo' => 3000,
        'hors_togo' => 5000,
    ];

    public function checkout()
    {
        try {
            $cart = $this->cleanCart();
            if (empty($cart)) {
                return redirect()->route('cart.index');
            }

            $items = [];
            $total = 0;
            $estPrecommande = false;

            foreach ($cart as $productId => $quantity) {
                $product = Product::find($productId);
                if (! $product) {
                    continue;
                }
                if ($product->estEnPrecommande()) {
                    $estPrecommande = true;
                }
                $lineTotal = $product->price * $quantity;
                $total += $lineTotal;
                $items[] = compact('product', 'quantity', 'lineTotal');
            }

            if ($items === []) {
                return redirect()->route('cart.index')->withErrors(['cart' => 'Produits indisponibles. Actualisez votre panier.']);
            }

            return response(view('checkout.index', [
                'items' => $items,
                'total' => $total,
                'estPrecommande' => $estPrecommande,
                'zones' => $this->fraisParZone,
            ])->render());
        } catch (\Throwable $exception) {
            Log::error('Affichage commande impossible.', [
                'user_id' => Auth::id(),
                'cart' => session('cart', []),
                'exception' => $exception,
            ]);

            return redirect()->route('cart.index')->withErrors(['checkout' => 'Chargement commande impossible. Réessayez ou contactez support.']);
        }
    }
    public function store(Request $request)
    {
        $request->validate([
            'mode_livraison' => 'required|in:domicile,point_retrait',
            'zone_livraison' => 'required_if:mode_livraison,domicile|nullable|string',
            'adresse_livraison' => 'required_if:mode_livraison,domicile|nullable|string',
            'point_retrait' => 'required_if:mode_livraison,point_retrait|nullable|string',
            'moyen_paiement' => 'required|in:alphapay',
            'code_promo' => 'nullable|string',
        ]);

        $cart = $this->cleanCart();
        if (empty($cart)) {
            return redirect()->route('cart.index');
        }

        $order = DB::transaction(function () use ($request, $cart) {
            $items = [];
            $sousTotal = 0;
            $estPrecommande = false;
            $acompteTotal = 0;
            $soldeTotal = 0;

            foreach ($cart as $productId => $quantity) {
                $product = Product::whereKey($productId)->lockForUpdate()->first();
                if (! $product) {
                    continue;
                }
                if ($product->type === 'stock' && ! $product->estEnPrecommande() && $quantity > $product->stock) {
                    throw ValidationException::withMessages(['cart' => 'Stock insuffisant pour : '.$product->name.'.']);
                }
                $lineTotal = $product->price * $quantity;
                $sousTotal += $lineTotal;

                if ($product->estEnPrecommande()) {
                    $estPrecommande = true;
                    $acompteTotal += $product->montantAcompte() * $quantity;
                    $soldeTotal += $product->montantSolde() * $quantity;
                }

                $items[] = [
                    'product' => $product,
                    'quantity' => $quantity,
                    'unit_price' => $product->price,
                ];
            }

            if ($items === []) {
                throw ValidationException::withMessages(['cart' => 'Panier vide ou produits indisponibles.']);
            }

            $reduction = 0;
            $codePromo = null;
            if ($request->filled('code_promo')) {
                $promo = PromoCode::where('code', $request->code_promo)->first();
                if ($promo && $promo->estValide()) {
                    $promoCategoryIds = $promo->categories()->pluck('categories.id');
                    $eligibleTotal = $promoCategoryIds->isEmpty()
                        ? $sousTotal
                        : collect($items)
                            ->filter(fn (array $item) => $promoCategoryIds->contains($item['product']->category_id))
                            ->sum(fn (array $item) => $item['unit_price'] * $item['quantity']);
                    $reduction = $promo->calculerReduction($eligibleTotal);
                    $codePromo = $promo->code;
                    $promo->increment('usage_actuel');
                }
            }

            $fraisLivraison = $request->mode_livraison === 'domicile'
                ? ($this->fraisParZone[$request->zone_livraison] ?? 1000)
                : 0;

            $total = $sousTotal - $reduction + $fraisLivraison;
            $montantAcompte = $estPrecommande ? $acompteTotal : $total;
            $montantSolde = $estPrecommande ? ($soldeTotal + $fraisLivraison - $reduction) : 0;
            $affiliatePartner = User::whereKey(session('affiliate_partner_id'))
                ->where('account_type', 'partner')
                ->where('is_active', true)
                ->where('id', '!=', Auth::id())
                ->first();

            $order = Order::create([
                'reference' => 'HS-'.strtoupper(Str::random(8)),
                'user_id' => Auth::id(),
                'type' => $estPrecommande ? 'precommande' : 'stock',
                'mode_livraison' => $request->mode_livraison,
                'zone_livraison' => $request->zone_livraison,
                'frais_livraison' => $fraisLivraison,
                'adresse_livraison' => $request->adresse_livraison,
                'point_retrait' => $request->point_retrait,
                'sous_total' => $sousTotal,
                'reduction' => $reduction,
                'code_promo' => $codePromo,
                'total' => $total,
                'montant_acompte' => $montantAcompte,
                'montant_solde' => $montantSolde,
                'solde_echeance_at' => $estPrecommande ? now()->addHours(48) : null,
                'moyen_paiement' => $request->moyen_paiement,
                'statut_paiement' => 'en_attente',
                'statut' => 'en_attente',
                'affiliate_partner_id' => $affiliatePartner?->id,
            ]);

            foreach ($items as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item['product']->id,
                    'product_name' => $item['product']->name,
                    'unit_price' => $item['unit_price'],
                    'quantity' => $item['quantity'],
                ]);

                if ($item['product']->type === 'stock' && ! $item['product']->estEnPrecommande()) {
                    $item['product']->decrement('stock', $item['quantity']);
                }
            }

            return $order;
        });

        session()->forget('cart');
        $order->load('user');
        try {
            $order->user->notify(new OrderUpdateNotification($order, 'commande'));
        } catch (\Throwable $exception) {
            Log::error('Notification client non envoyee.', [
                'order_id' => $order->id,
                'error' => $exception->getMessage(),
            ]);
        }
        $this->notifyAdmins($order, 'nouvelle_commande');

        if ($order->moyen_paiement === 'alphapay') {
            try {
                return redirect()->away(app(AlphaPayService::class)->createCheckout($order));
            } catch (\Throwable $exception) {
                Log::error('Session AlphaPay impossible.', ['order_id' => $order->id, 'error' => $exception->getMessage()]);

                return redirect()->route('orders.show', $order)->withErrors(['paiement' => 'Paiement en ligne indisponible. Contactez notre équipe.']);
            }
        }

        return redirect()->route('orders.show', $order)->with('success', 'Commande enregistrée. Confirmez le paiement '.strtoupper($order->moyen_paiement).' pour valider.')->with('order_created', true);
    }

    public function show(Order $order)
    {
        $this->authorizeOwner($order);

        $order->load(['items.product.images', 'statusHistory.user']);

        return view('orders.show', compact('order'));
    }

    public function myOrders()
    {
        $orders = Order::where('user_id', Auth::id())
            ->with(['items.product.images'])
            ->latest()
            ->paginate(10);

        return view('orders.index', compact('orders'));
    }

    protected function authorizeOwner(Order $order): void
    {
        abort_unless($order->user_id === Auth::id() || Auth::user()?->is_admin, 403);
    }

    protected function cleanCart(): array
    {
        $cart = session('cart', []);
        $validCart = [];

        foreach ($cart as $productId => $quantity) {
            if (Product::whereKey($productId)->exists() && (int) $quantity > 0) {
                $validCart[$productId] = (int) $quantity;
            }
        }

        if ($validCart !== $cart) {
            session()->put('cart', $validCart);
        }

        return $validCart;
    }

    protected function notifyAdmins(Order $order, string $event): void
    {
        User::where('is_admin', true)->get()->each(function (User $admin) use ($order, $event) {
            try {
                $admin->notify(new AdminOrderNotification($order, $event));
            } catch (\Throwable $exception) {
                Log::error('Notification admin non envoyee.', [
                    'order_id' => $order->id,
                    'admin_id' => $admin->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        });
    }
}
