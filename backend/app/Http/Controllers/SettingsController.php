<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\User;
use App\Services\AssociationLogo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SettingsController extends ApiController
{
    private function entitySettings(): Entity
    {
        return Entity::firstOrCreate(['type' => 'settings'], ['data' => ['association_name' => '中華復甦更新發展協會', 'contact_phone' => '', 'contact_email' => '', 'address' => '']]);
    }

    private function recheck(Request $r): void
    {
        User::orderBy('id')->lockForUpdate()->get();
        $r->user()->refresh()->unsetRelation('roles');
        $this->permit($r, 'settings.manage.all');
    }

    public function settings(Request $r)
    {
        $this->permit($r, 'settings.manage.all');
        if ($r->isMethod('get')) {
            return app(AssociationLogo::class)->payload($this->entitySettings()->data);
        }
        $v = $r->validate(['association_name' => 'required|string|max:200', 'contact_phone' => 'nullable|string|max:100', 'contact_email' => 'nullable|email', 'address' => 'nullable|string|max:500', 'logo_file_id' => 'nullable|integer']);

        return DB::transaction(function () use ($r, $v) {
            $this->recheck($r);
            if (isset($v['logo_file_id']) && ! app(AssociationLogo::class)->validFile((int) $v['logo_file_id'])) {
                throw ValidationException::withMessages(['logo_file_id' => '標誌必須是已上傳的公開 JPG、PNG 或 WebP 圖片。']);
            }
            $e = $this->entitySettings();
            $e = Entity::lockForUpdate()->findOrFail($e->id);
            $before = $e->data;
            $e->update(['data' => array_merge($before, $v)]);
            $this->audit($r, $e, $before, 'update');

            return app(AssociationLogo::class)->payload($e->data);
        });
    }

    public function logo(Request $r)
    {
        $this->permit($r, 'settings.manage.all');
        $r->validate(['file' => 'required|file|max:5120|mimes:jpg,jpeg,png,webp']);
        $uploaded = $r->file('file');
        $image = @getimagesize($uploaded->getRealPath());
        if (! $image || ! in_array($image['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw ValidationException::withMessages(['file' => '請上傳有效的 JPG、PNG 或 WebP 圖片。']);
        }
        $path = null;
        try {
            return DB::transaction(function () use ($r, $uploaded, &$path) {
                $this->recheck($r);
                $e = $this->entitySettings();
                $e = Entity::lockForUpdate()->findOrFail($e->id);
                $before = $e->data;
                $path = $uploaded->store('association-logos');
                $file = Entity::create(['type' => 'files', 'owner_id' => $r->user()->id, 'data' => ['path' => $path, 'name' => $uploaded->getClientOriginalName(), 'visibility' => 'public', 'category' => '協會標誌', 'title' => '協會標誌']]);
                $e->update(['data' => array_merge($before, ['logo_file_id' => $file->id])]);
                $this->audit($r, $e, $before, 'logo.uploaded');

                return app(AssociationLogo::class)->payload($e->data);
            });
        } catch (\Throwable $error) {
            if ($path) {
                Storage::delete($path);
            }
            throw $error;
        }
    }

    private function audit(Request $r, Entity $e, array $before, string $action): void
    {
        Entity::create(['type' => 'audit', 'owner_id' => $r->user()->id, 'data' => ['module' => 'settings', 'subject_id' => $e->id, 'action' => $action, 'before' => $before, 'after' => $e->data]]);
    }
}
