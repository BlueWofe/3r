<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssociationLogoTest extends TestCase
{
    use RefreshDatabase;

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jL1sAAAAASUVORK5CYII=');
    }

    private function account(bool $manager = true): User
    {
        $u = User::create(['name' => '示範管理', 'phone' => '09'.str_pad((string) (User::count() + 1), 8, '0', STR_PAD_LEFT), 'email' => uniqid().'@example.test', 'password' => 'Test-only-password']);
        $u->roles()->attach(Role::create(['name' => '設定管理', 'slug' => uniqid('role'), 'permissions' => $manager ? ['settings.manage.all'] : [], 'active' => true]));

        return $u;
    }

    private function settings(): Entity
    {
        return Entity::create(['type' => 'settings', 'data' => ['association_name' => '示範協會', 'contact_phone' => '0900000000', 'contact_email' => 'demo@example.test', 'address' => '示範地址', 'internal_note' => '保留私有設定']]);
    }

    private function file(string $visibility = 'public', bool $image = true): Entity
    {
        $path = 'files/'.uniqid().($image ? '.png' : '.txt');
        Storage::put($path, $image ? $this->png() : 'not an image');

        return Entity::create(['type' => 'files', 'data' => ['path' => $path, 'name' => $image ? 'demo.png' : 'demo.txt', 'visibility' => $visibility]]);
    }

    public function test_upload_requires_settings_permission_and_valid_raster_with_size_limit(): void
    {
        Storage::fake();
        $this->post('/api/v1/settings/logo', ['file' => UploadedFile::fake()->createWithContent('demo.png', $this->png())], ['Accept' => 'application/json'])->assertUnauthorized();
        $this->actingAs($this->account(false))->post('/api/v1/settings/logo', ['file' => UploadedFile::fake()->createWithContent('demo.png', $this->png())], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($this->account());
        foreach ([UploadedFile::fake()->createWithContent('script.svg', '<svg></svg>'), UploadedFile::fake()->createWithContent('fake.png', 'not an image'), UploadedFile::fake()->create('large.png', 5121, 'image/png')] as $file) {
            $this->post('/api/v1/settings/logo', ['file' => $file], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
        }
        $this->assertSame(0, Entity::where('type', 'files')->count());
    }

    public function test_settings_manager_uploads_public_logo_without_content_permission_and_preserves_settings(): void
    {
        Storage::fake();
        $settings = $this->settings();
        $actor = $this->account();
        $id = $this->actingAs($actor)->post('/api/v1/settings/logo', ['file' => UploadedFile::fake()->createWithContent('demo.png', $this->png())], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('association_name', '示範協會')->assertJsonPath('internal_note', '保留私有設定')->json('logo_file_id');
        $file = Entity::findOrFail($id);
        $this->assertSame('public', $file->data['visibility']);
        Storage::assertExists($file->data['path']);
        $this->getJson('/api/v1/settings')->assertJsonPath('logo_url', '/api/v1/files/'.$id.'/download');
        $this->putJson('/api/v1/settings', ['association_name' => '改協會名稱'])->assertOk()->assertJsonPath('logo_file_id', $id)->assertJsonPath('contact_phone', '0900000000');
        $this->assertSame('保留私有設定', $settings->fresh()->data['internal_note']);
        $this->assertDatabaseHas('entities', ['type' => 'audit', 'owner_id' => $actor->id]);
        $this->assertSame('logo.uploaded', Entity::where('type', 'audit')->first()->data['action']);
        $this->putJson('/api/v1/settings', ['association_name' => '改協會名稱', 'logo_file_id' => null])->assertOk()->assertJsonPath('logo_url', null)->assertJsonPath('logo_file_id', null);
    }

    public function test_existing_logo_references_reject_private_nonimage_missing_and_zero_without_mutating_settings(): void
    {
        Storage::fake();
        $settings = $this->settings();
        $public = $this->file();
        $private = $this->file('private');
        $text = $this->file('public', false);
        $this->actingAs($this->account());
        $this->putJson('/api/v1/settings', ['association_name' => '示範協會', 'logo_file_id' => $public->id])->assertOk()->assertJsonPath('logo_file_id', $public->id);
        foreach ([$private->id, $text->id, 999, 0] as $id) {
            $this->putJson('/api/v1/settings', ['association_name' => '不該保存', 'logo_file_id' => $id])->assertUnprocessable()->assertJsonValidationErrors('logo_file_id');
            $this->assertSame($public->id, $settings->fresh()->data['logo_file_id']);
            $this->assertSame('示範協會', $settings->fresh()->data['association_name']);
        }
    }

    public function test_public_contact_logo_is_derived_and_revocation_missing_storage_or_invalid_image_falls_back(): void
    {
        Storage::fake();
        $settings = $this->settings();
        $file = $this->file();
        $settings->update(['data' => array_merge($settings->data, ['logo_file_id' => $file->id, 'logo_url' => 'https://attacker.example/logo'])]);
        $this->getJson('/api/v1/public/contact')->assertOk()->assertJsonPath('data.logo_url', '/api/v1/files/'.$file->id.'/download')->assertJsonMissing(['internal_note' => '保留私有設定']);
        $file->update(['data' => array_merge($file->data, ['visibility' => 'private'])]);
        $this->getJson('/api/v1/public/contact')->assertJsonPath('data.logo_url', null);
        $file->update(['data' => array_merge($file->data, ['visibility' => 'public'])]);
        Storage::delete($file->data['path']);
        $this->getJson('/api/v1/public/contact')->assertJsonPath('data.logo_url', null);
        Storage::put($file->data['path'], 'not an image');
        $this->getJson('/api/v1/public/contact')->assertJsonPath('data.logo_url', null);
    }
}
