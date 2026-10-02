<?php

namespace App\Services;

use App\Models\Prison;
use Illuminate\Validation\ValidationException;

class PrisonDirectory
{
    public function resolve(array $input, ?int $existingId = null, bool $allowImport = true, bool $required = true): array
    {
        $id = $input['prison_id'] ?? null;
        $name = trim((string) ($input['prison'] ?? ''));
        if (! array_key_exists('prison_id', $input) && ! array_key_exists('prison', $input)) {
            $id = $existingId;
        }
        if (! $id && $name !== '') {
            $prison = Prison::where('name', $name)->first();
            if (! $prison && $allowImport) {
                $prison = Prison::firstOrCreate(['name' => $name]);
            }
            $id = $prison?->id;
        }
        if (! $id) {
            if ($required || $name !== '') {
                throw ValidationException::withMessages(['prison_id' => '請選擇監所。']);
            }

            return ['prison_id' => null, 'prison' => null];
        }
        $prison = Prison::lockForUpdate()->find($id);
        if (! $prison || (! $prison->active && (int) $id !== $existingId)) {
            throw ValidationException::withMessages(['prison_id' => '請選擇啟用的監所；停用監所僅可保留既有紀錄。']);
        }

        return ['prison_id' => $prison->id, 'prison' => $prison->name];
    }

    public function payload(array $data, ?int $id = null): array
    {
        $id = $id ?? ($data['prison_id'] ?? null);
        $prison = $id ? Prison::find($id) : null;

        return array_merge($data, ['prison_id' => $id, 'prison' => $prison?->name ?? ($data['prison'] ?? null)]);
    }
}
