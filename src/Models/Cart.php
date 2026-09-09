<?php

namespace DigitalSelf\LaravelCart\Models;

use DigitalSelf\LaravelCart\Cartable;
use DigitalSelf\LaravelCart\Database\Factories\CartFactory;
use DigitalSelf\LaravelCart\Events\LaravelCartDecreaseQuantityEvent;
use DigitalSelf\LaravelCart\Events\LaravelCartEmptyEvent;
use DigitalSelf\LaravelCart\Events\LaravelCartIncreaseQuantityEvent;
use DigitalSelf\LaravelCart\Events\LaravelCartRemoveItemEvent;
use DigitalSelf\LaravelCart\Events\LaravelCartStoreItemEvent;
use DigitalSelf\LaravelCart\Models\Scopes\ActiveCartScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ScopedBy([ActiveCartScope::class])]
class Cart extends Model
{
    use HasFactory, SoftDeletes;

    public static function newFactory(): CartFactory
    {
        return CartFactory::new();
    }

    /**
     * Fillable columns.
     *
     * @var string[]
     */
    protected $fillable = ['customer_id', 'canceled_at', 'completed_at'];

    /**
     * The relations to eager load on every query.
     *
     * @var string[]
     */
    protected $with = ['items'];

    protected $casts = [
        'completed_at' => 'datetime',
        'canceled_at' => 'datetime',
    ];

    /**
     * Create a new instance of the model.
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = config('laravel-cart.carts.table', 'carts');
    }

    // Relations

    /**
     * Relation one-to-many, CartItem model.
     */
    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function customer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(config('laravel-cart.customer_model'), 'customer_id');
    }

    // Scopes

    /**
     * @throws \Exception
     */
    public function scopeFirstOrCreateWithStoreItems(
        Builder $query,
        Model $item,
        int $quantity = 1,
        ?int $customerId = null
    ): Builder {
        if (is_null($customerId)) {
            $customerId = auth(config('laravel-cart.guard'))->id();
        }
        if (! $item instanceof Cartable) {
            throw new \Exception('The item must be an instance of Cartable');
        }

        $cart = $query->firstOrCreate(['customer_id' => $customerId]);
        $cartItem = new CartItem([
            'itemable_id' => $item->getKey(),
            'itemable_type' => $item::class,
            'quantity' => $quantity,
        ]);

        $cart->items()->save($cartItem);

        // Dispatch Event
        LaravelCartStoreItemEvent::dispatch();

        return $query;
    }

    public static function addItem(int $customerId, Cartable $product): Cart
    {
        $cart = self::query()->firstOrCreate(['customer_id' => $customerId]);
        $cartItem = $cart->items->first(function ($item) use ($product) {
            return $item->itemable_type == get_class($product) && $item->itemable_id == $product->id;
        });
        if (empty($cartItem) || ! $cartItem->exists) {
            $cart->storeItem($product);
        } else {
            $cart->increaseQuantity(item: $product);
        }

        return $cart;
    }

    // Methods

    /**
     * Calculate price by quantity of items.
     */
    public function calculatedPriceByQuantity(): float
    {
        return array_sum($this->lineTotals());
    }

    /**
     * What each line of this cart is worth, keyed by cart item id.
     *
     * The single definition of this cart's arithmetic, and the one callers
     * should read. Two rules live here and nowhere else:
     *
     *  - a line with a {@see CartItem::priceOverride()} bills at that price;
     *  - every other line is priced at the rate its itemable charges for the
     *    cart's WHOLE holding of that itemable, not for this line alone.
     *
     * Callers needing a subtotal of SOME lines — a product-restricted coupon's
     * eligible lines, a tickets-only subtotal — must add up these figures
     * rather than re-deriving them from the itemables. Every re-derivation so
     * far has ended up measuring a different cart from the one that gets
     * charged.
     *
     * TAX: this cart has no idea. The figures come back in whatever basis the
     * itemable's own methods and the override amount are written in, so a
     * consumer that mixes the two bases within one cart gets a meaningless
     * sum, and one that wants a specific basis has to convert. Use these for
     * comparing a cart against itself — a coupon threshold, a discount base,
     * an amount to charge — and derive display prices from the itemable, where
     * the tax treatment is known.
     *
     * @return array<int, float>
     */
    public function lineTotals(): array
    {
        $items = $this->resolveItems();
        $quantities = $this->quantitiesFor($items);

        $totals = [];

        foreach ($items as $item) {
            $totals[$item->id] = $this->lineTotal($item, $quantities);
        }

        return $totals;
    }

    /**
     * How much of each itemable this cart holds, across every line, keyed
     * "Type:id".
     *
     * This is the quantity a {@see \DigitalSelf\LaravelCart\QuantityPriced}
     * itemable is asked to price — see that interface for why it is the
     * cart-wide total. Overridden lines are left out: an agreed price is not
     * evidence of volume.
     *
     * @return array<string, int>
     */
    public function quantityByItemable(): array
    {
        return $this->quantitiesFor($this->resolveItems());
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CartItem>  $items
     * @return array<string, int>
     */
    protected function quantitiesFor($items): array
    {
        $totals = [];

        foreach ($items as $item) {
            if ($item->hasPriceOverride()) {
                continue;
            }

            $key = $item->itemable_type.':'.$item->itemable_id;
            $totals[$key] = ($totals[$key] ?? 0) + (int) $item->quantity;
        }

        return $totals;
    }

    /**
     * @param  array<string, int>  $quantities
     */
    protected function lineTotal(CartItem $item, array $quantities): float
    {
        $override = $item->priceOverride();

        if ($override !== null) {
            return $override * (int) $item->quantity;
        }

        $itemable = $item->itemable;

        // A line whose itemable has been deleted underneath it is worth
        // nothing rather than fatal: carts outlive catalogues.
        if (! $itemable) {
            return 0.0;
        }

        // method_exists and not instanceof QuantityPriced: the method predates
        // the interface, and an implementor that has not declared it yet must
        // keep working exactly as before.
        if (! method_exists($itemable, 'getPriceByQuantity')) {
            return (int) $item->quantity * (float) $itemable->getPrice();
        }

        $lineQuantity = (int) $item->quantity;
        $wholeQuantity = $quantities[$item->itemable_type.':'.$item->itemable_id] ?? $lineQuantity;

        // The only line of this itemable: ask for exactly this quantity and
        // hand the answer back untouched. No division, so a single-line cart
        // is arithmetically identical to what this method returned before
        // cart-wide quantities existed.
        if ($wholeQuantity === $lineQuantity) {
            return (float) $itemable->getPriceByQuantity($lineQuantity);
        }

        // Split across lines: the itemable prices the whole holding, and this
        // line takes its share at the same unit rate. Exact for a volume
        // function, which is what QuantityPriced requires — a graduated one
        // would need an allocation policy this deliberately does not invent.
        $whole = (float) $itemable->getPriceByQuantity($wholeQuantity);

        return $wholeQuantity > 0
            ? ($whole / $wholeQuantity) * $lineQuantity
            : 0.0;
    }

    /**
     * Always a fresh read, matching what this class did before: callers create
     * items and then total the cart in the same request, and a relation loaded
     * earlier would answer for the cart as it was. `itemable` is eager loaded
     * because every line asks for it.
     *
     * @return \Illuminate\Support\Collection<int, CartItem>
     */
    protected function resolveItems()
    {
        return $this->items()->with('itemable')->get();
    }

    /**
     * Store multiple items in cart.
     */
    public function storeItems(array $items): static
    {
        foreach ($items as $item) {
            $this->storeItem($item);
        }

        return $this;
    }

    /**
     * Store cart item in cart.
     */
    public function storeItem(Model|array $item): static
    {
        if (is_array($item)) {
            $item['itemable_id'] = $item['itemable']->getKey();
            $item['itemable_type'] = get_class($item['itemable']);
            $item['quantity'] = (int) $item['quantity'];

            if ($item['itemable'] instanceof Cartable) {
                $this->items()->create($item);
            } else {
                throw new \RuntimeException(sprintf('The item must be an instance of %s', Cartable::class));
            }
        } else {
            if ($item instanceof Cartable) {
                $this->items()->create([
                    'itemable_id' => $item->getKey(),
                    'itemable_type' => get_class($item),
                    'itemable_quantity' => 1,
                ]);
            } else {
                throw new \RuntimeException(sprintf('The item must be an instance of %s', Cartable::class));
            }
        }

        // Dispatch Event
        LaravelCartStoreItemEvent::dispatch();

        return $this;
    }

    /**
     * Remove a single item from the cart
     */
    public function removeItem(Model $item): static
    {
        $itemToDelete = $this->items()->find($item->getKey());

        if ($itemToDelete) {
            $itemToDelete->delete();
        }

        // Dispatch Event
        LaravelCartRemoveItemEvent::dispatch();

        return $this;
    }

    /**
     * Remove every item from the cart
     */
    public function emptyCart(): static
    {
        $this->items()->delete();

        // Dispatch Event
        LaravelCartEmptyEvent::dispatch();

        return $this;
    }

    /**
     * Increase the quantity of the item.
     */
    public function increaseQuantity(Model $item, int $quantity = 1): static
    {
        $item = $this->items()->firstWhere('itemable_id', $item->getKey());
        if (! $item) {
            throw new \RuntimeException('The item not found');
        }

        $item->increment('quantity', $quantity);

        // Dispatch Event
        LaravelCartIncreaseQuantityEvent::dispatch($item);

        return $this;
    }

    /**
     * Decrease the quantity of the item.
     */
    public function decreaseQuantity(Model $item, int $quantity = 1): static
    {
        $item = $this->items()->find($item->getKey());
        if (! $item) {
            throw new \RuntimeException('The item not found');
        }

        $item->decrement('quantity', $quantity);

        // Dispatch Event
        LaravelCartDecreaseQuantityEvent::dispatch($item);

        return $this;
    }
}
