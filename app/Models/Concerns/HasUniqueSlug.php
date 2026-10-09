<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Slug único desde `name` cuando falta, con sufijo -2, -3… si colisiona. No se
 * regenera al cambiar el nombre: los links publicados no se rompen.
 *
 * Con SoftDeletes busca también entre las filas dadas de baja, porque el índice
 * único las cubre.
 */
trait HasUniqueSlug
{
    /** Base para un nombre que no produce slug (sólo símbolos). */
    abstract protected static function slugFallback(): string;

    protected static function bootHasUniqueSlug(): void
    {
        static::saving(function (Model $model) {
            if (empty($model->getAttribute('slug')) && ! empty($model->getAttribute('name'))) {
                $model->setAttribute('slug', static::generateUniqueSlug(
                    (string) $model->getAttribute('name'),
                    $model->getKey() !== null ? (int) $model->getKey() : null,
                ));
            }
        });
    }

    protected static function generateUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: static::slugFallback();
        $slug = $base;
        $suffix = 2;

        $query = fn () => in_array(SoftDeletes::class, class_uses_recursive(static::class), true)
            ? static::withTrashed()
            : static::query();

        while (
            $query()
                ->where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
