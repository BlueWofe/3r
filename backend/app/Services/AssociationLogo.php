<?php

namespace App\Services;

use App\Models\Entity;
use Illuminate\Support\Facades\Storage;

class AssociationLogo
{
    public function validFile(?int $id): ?Entity
    {
        if (! $id) {
            return null;
        }
        $file = Entity::where('type', 'files')->find($id);
        if (! $file || ($file->data['visibility'] ?? '') !== 'public') {
            return null;
        }
        $path = $file->data['path'] ?? null;
        try {
            if (! $path || ! Storage::exists($path) || ! in_array(Storage::mimeType($path), ['image/jpeg', 'image/png', 'image/webp'], true)) {
                return null;
            }
            $image = @getimagesizefromstring(Storage::get($path));

            return $image && in_array($image['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) ? $file : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function url(array $settings): ?string
    {
        $id = $settings['logo_file_id'] ?? null;

        return is_numeric($id) && $this->validFile((int) $id) ? '/api/v1/files/'.(int) $id.'/download' : null;
    }

    public function payload(array $settings): array
    {
        return array_merge($settings, ['logo_file_id' => $settings['logo_file_id'] ?? null, 'logo_url' => $this->url($settings)]);
    }
}
