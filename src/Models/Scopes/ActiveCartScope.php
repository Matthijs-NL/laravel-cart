<?php


namespace DigitalSelf\LaravelCart\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ActiveCartScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNull('completed_at')
            ->where(function ($query) {
                $query->whereNull('canceled_at')
                    ->orWhere('canceled_at', '>', now()->subDays(3));
            });
    }
}
