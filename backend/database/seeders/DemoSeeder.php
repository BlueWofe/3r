<?php

namespace Database\Seeders;

use App\Models\Entity;
use App\Models\Role;
use App\Models\ServiceSession;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! config('ministry.demo_seed')) {
            return;
        }
        $password = config('ministry.demo_password');
        if (! $password || strlen($password) < 10) {
            throw new \RuntimeException('DEMO_PASSWORD must be supplied and at least 10 characters.');
        }
        $admin = Role::firstOrCreate(['slug' => 'system-admin'], ['name' => '系統管理員', 'permissions' => []]);
        $teacher = Role::firstOrCreate(['slug' => 'teacher'], ['name' => '教師', 'permissions' => ['schedule.read.own', 'schedule.update.own', 'attendance.create.own', 'resources.read.own', 'resources.create.own', 'meetings.read.own', 'forms.read.own', 'donations.read.own', 'reports.read.own']]);
        $member = Role::firstOrCreate(['slug' => 'member'], ['name' => '會員', 'permissions' => ['forms.read.own', 'donations.read.own']]);
        Role::firstOrCreate(['slug' => 'schedule-manager'], ['name' => '課務管理員', 'permissions' => ['schedule.read.all', 'schedule.create.all', 'schedule.update.all', 'attendance.update.all', 'reports.read.all']]);
        Role::firstOrCreate(['slug' => 'case-manager'], ['name' => '個案管理員', 'permissions' => ['cases.read.all', 'cases.create.all', 'cases.update.all', 'cases.export.all']]);
        Role::firstOrCreate(['slug' => 'finance-manager'], ['name' => '財務管理員', 'permissions' => ['donations.read.all', 'reports.read.all']]);
        Role::firstOrCreate(['slug' => 'resource-manager'], ['name' => '資源管理員', 'permissions' => ['resources.read.all', 'resources.create.all', 'resources.update.all', 'resources.delete.all', 'meetings.read.all', 'meetings.create.all', 'meetings.update.all', 'forms.read.all', 'forms.create.all', 'forms.update.all', 'forms.export.all']]);
        $content = Role::firstOrCreate(['slug' => 'content-manager'], ['name' => '內容管理員', 'permissions' => ['content.read.all', 'content.create.all', 'content.update.all', 'content.delete.all', 'content.publish.all', 'resources.read.all', 'resources.create.all']]);
        $users = [];
        foreach ([1 => '示範管理教師', 2 => '示範教師甲', 3 => '示範會員', 4 => '示範教師乙', 5 => '示範內容編輯'] as $n => $name) {
            $phone = '090000000'.$n;
            $u = User::firstOrCreate(['phone' => $phone], ['name' => $name, 'email' => $phone.'@demo.invalid', 'password' => $password]);
            $u->roles()->sync(match ($n) {
                1 => [$admin->id, $teacher->id],2,4 => [$teacher->id],3 => [$member->id],5 => [$content->id]
            });
            $users[$n] = $u;
        }
        foreach ([['page', 'about', '關於我們'], ['page', 'history', '示範協會沿革'], ['page', 'organization', '示範組織架構'], ['page', 'contact', '聯絡我們'], ['page', 'privacy', '隱私政策'], ['news', 'demo-news', '示範事工消息'], ['product', 'demo-product', '示範食品目錄']] as [$kind,$slug,$title]) {
            if (! Entity::where('type', 'contents')->get()->contains(fn ($e) => ($e->data['slug'] ?? '') === $slug)) {
                Entity::create(['type' => 'contents', 'owner_id' => $users[5]->id, 'data' => ['kind' => $kind, 'slug' => $slug, 'title' => $title, 'body' => '示範內容：此處為合成資料，不包含真實個案。以關懷、陪伴與信仰服務為核心。', 'summary' => '示範內容，僅供測試', 'category' => '示範', 'status' => 'published', 'sort_order' => 1, 'metadata' => ['demo' => true, 'display_only' => true], 'views' => 0]]);
            }
        }
        if (! ServiceSession::exists()) {
            foreach ([0, 7, 14] as $days) {
                $s = ServiceSession::create(['data' => ['title' => '示範福音陪伴服務', 'prison' => '示範監所', 'location' => '示範教室', 'participant_count' => 12, 'service_date' => now('Asia/Taipei')->addDays($days)->format('Y-m-d'), 'start_time' => '09:00', 'end_time' => '11:00', 'status' => 'scheduled', 'original_teacher_count' => 2]]);
                foreach ([1, 2] as $n) {
                    $s->assignments()->create(['teacher_id' => $users[$n]->id]);
                }
            }
        }
        if (! Entity::where('type', 'cases')->exists()) {
            Entity::create(['type' => 'cases', 'owner_id' => $users[1]->id, 'data' => ['code' => 'DEMO-001', 'name' => '示範個案甲', 'status' => '服務中', 'prison' => '示範監所', 'contact' => '合成資料', 'assigned_user_id' => $users[2]->id, 'records' => []]]);
        }
        if (! Entity::where('type', 'meetings')->exists()) {
            Entity::create(['type' => 'meetings', 'owner_id' => $users[1]->id, 'data' => ['title' => '示範事工會議', 'meeting_date' => now('Asia/Taipei')->format('Y-m-d'), 'agenda' => '示範議程', 'minutes' => '示範紀錄', 'decisions' => '示範決議', 'role_ids' => [], 'file_ids' => []]]);
        }
        if (! Entity::where('type', 'forms')->exists()) {
            $fields = [['key' => 'feedback', 'label' => '服務心得', 'type' => 'textarea', 'required' => true]];
            Entity::create(['type' => 'forms', 'owner_id' => $users[1]->id, 'data' => ['title' => '示範服務回饋', 'description' => '示範表單', 'status' => 'published', 'role_ids' => [], 'version' => 1, 'fields' => $fields, 'snapshots' => [['version' => 1, 'fields' => $fields, 'published_at' => now()->toIso8601String()]]]]);
        }
    }
}
