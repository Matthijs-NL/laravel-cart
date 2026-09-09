<?php

namespace DigitalSelf\LaravelCart\Models;

use DigitalSelf\LaravelCart\Database\Factories\CartItemFactory;
use DigitalSelf\LaravelCart\Observers\CartItemObserve;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[ObservedBy([CartItemObserve::class])]
class CartItem extends Model
{
    use HasFactory;

    public static function newFactory(): CartItemFactory
    {
        return CartItemFactory::new();
    }

    /**
     * Fillable columns.
     *
     * @var string[]
     */
    protected $guarded = ['id'];

    /**
     * @var string[]
     */
    protected $casts = [
        'options' => 'array',
    ];

    /**
     * Create a new instance of the model.
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = config('laravel-cart.cart_items.table', 'cart_items');
    }

    // Methods

    /**
     * Get option.
     */
    public function getOption(string $option): mixed
    {
        $options = json_decode($this->options, true);

        return $options[$option] ?? null;
    }

    /**
     * Get options.
     */
    public function getOptions(): mixed
    {
        return json_decode($this->options, true);
    }

    /**
     * Set option.
     */
    public function setOption(string $key, mixed $value): static
    {
        $this->update(['options' => json_encode([$key => $value])]);

        return $this;
    }

    /**
     * Add option.
     */
    public function addOption(string $key, mixed $value): static
    {
        $options = $this->getOptions();
        if (! is_array($options)) {
            $options = json_decode($options, true);
        }

        $options[$key] = $value;

        $this->options = json_encode($options);
        $this->save();

        return $this;
    }

    /**
     * A price agreed for THIS line, replacing whatever its itemable charges.
     *
     * Formalised on the model rather than left as a convention in `options`
     * because the cart has to act on it in two ways at once: the line bills at
     * this amount, AND its quantity stays out of the itemable's volume total
     * ({@see Cart::quantityByItemable()}). An override is a negotiated price —
     * a half-hour booking at half rate, an upgrade priced against what was
     * already paid — so it is not evidence of volume and must not push the
     * other lines of that itemable into a cheaper band.
     *
     * That second half is what consumers kept missing: the amount was honoured
     * where the charge was built and ignored where the same cart was totalled
     * for display, so a cart holding an overridden line beside a normal one
     * quoted a different price from the one it charged.
     *
     * Reads `options['price_override']` — the key consumers already write — and
     * treats zero as "no override", matching the `! empty()` test this replaces.
     * A genuinely free line is expressed with a coupon; a stray 0 from an unset
     * form field must not silently zero a paid line.
     */
    public function priceOverride(): ?float
    {
        $options = $this->options;

        if (! is_array($options)) {
            $options = json_decode((string) $options, true) ?: [];
        }

        $override = $options['price_override'] ?? null;

        return is_numeric($override) && (float) $override > 0
            ? (float) $override
            : null;
    }

    public function hasPriceOverride(): bool
    {
        return $this->priceOverride() !== null;
    }

    // Relations

    /**
     * Relation polymorphic, inverse one-to-one or many relationship.
     */
    public function itemable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Relation one-to-many, Cart model.
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class, 'cart_id');
    }
}
