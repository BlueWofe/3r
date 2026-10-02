<?php

namespace App\Models\Concerns;

use App\Models\Prison;
use App\Services\PrisonDirectory;

trait HasPrison
{
    public function initializeHasPrison(): void
    {
        $this->mergeCasts(['prison_id' => 'integer']);
    }

    public function prison()
    {
        return $this->belongsTo(Prison::class);
    }

    public static function bootHasPrison(): void
    {
        static::saving(function ($model) {
            if (array_key_exists('prison_id', $model->data ?? [])) {
                $model->prison_id = $model->data['prison_id'];
            }
        });
    }

    public function prisonData(): array
    {
        if ($this->relationLoaded('prison')) {
            return array_merge($this->data, ['prison_id' => $this->prison_id, 'prison' => $this->prison?->name ?? ($this->data['prison'] ?? null)]);
        }

        return app(PrisonDirectory::class)->payload($this->data, $this->prison_id);
    }
}
