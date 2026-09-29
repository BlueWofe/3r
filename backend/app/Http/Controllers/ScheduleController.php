<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Entity;
use App\Models\ServiceSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScheduleController extends ApiController
{
    private function access(Request $r, ServiceSession $s, string $action = 'read'): void
    {
        abort_unless($r->user()->canDo("schedule.$action.all") || ($r->user()->canDo("schedule.$action.own") && $s->assignments()->where('teacher_id', $r->user()->id)->exists()), 403);
    }

    private function output(ServiceSession $s): array
    {
        return $s->data + ['id' => $s->id, 'version' => $s->version, 'assignments' => $s->assignments()->with('teacher:id,name')->get(), 'invitations' => DB::table('invitations')->whereIn('assignment_id', $s->assignments()->pluck('id'))->get(), 'events' => Entity::where('type', 'changes')->get()->filter(fn ($e) => ($e->data['session_id'] ?? 0) === $s->id)->map->publicData()->values()];
    }

    private function snapshot(ServiceSession $s): array
    {
        return $s->data + ['version' => $s->version, 'assignments' => $s->assignments()->get()->toArray()];
    }

    private function mutable(ServiceSession $s): void
    {
        abort_unless($s->data['status'] === 'scheduled', 409, '排課已取消');
        abort_if(now('Asia/Taipei')->gte(Carbon::parse($s->data['service_date'].' '.$s->data['end_time'], 'Asia/Taipei')) || $s->assignments()->whereNotNull('attendance')->exists(), 409, '課程已結束或已有簽到');
    }

    private function event(Request $r, ServiceSession $s, string $action, string $reason, array $before = []): void
    {
        Entity::create(['type' => 'changes', 'owner_id' => $r->user()->id, 'data' => ['session_id' => $s->id, 'action' => $action, 'reason' => $reason, 'version' => $s->version, 'actor' => $r->user()->name, 'acknowledged_by' => [], 'before' => $before, 'after' => $this->snapshot($s)]]);
        $recipients = $s->assignments()->pluck('teacher_id')->merge(User::where('active', true)->get()->filter(fn ($u) => $u->canDo('schedule.update.all'))->pluck('id'))->unique();
        foreach ($recipients as $recipient) {
            Entity::create(['type' => 'notifications', 'owner_id' => $recipient, 'data' => ['title' => $s->data['title'], 'message' => $action.'：'.$reason, 'session_id' => $s->id, 'read' => false]]);
            $line = Entity::where('type', 'line')->where('owner_id', $recipient)->first();
            if (($line?->data['bound'] ?? false) && ($line?->data['subscribed'] ?? false)) {
                Entity::create(['type' => 'line-outbox', 'owner_id' => $recipient, 'data' => ['mode' => 'mock', 'message' => $action.'：'.$reason, 'delivered' => false]]);
            }
        }
    }

    private function conflict(int $teacher, array $d, int $except = 0): bool
    {
        return ServiceSession::where('id', '!=', $except)->whereHas('assignments', fn ($q) => $q->where('teacher_id', $teacher)->where('status', 'assigned'))->get()->contains(fn ($s) => $s->data['status'] === 'scheduled' && $s->data['service_date'] === $d['service_date'] && $s->data['start_time'] < $d['end_time'] && $s->data['end_time'] > $d['start_time']);
    }

    private function teacher(int $id): User
    {
        $u = User::where('active', true)->findOrFail($id);
        abort_unless($u->canDo('schedule.read.own') || $u->canDo('schedule.update.own'), 422, '此帳號不是啟用教師');

        return $u;
    }

    public function sessions(Request $r, ?int $id = null)
    {
        if ($r->isMethod('get')) {
            if ($id) {
                $s = ServiceSession::findOrFail($id);
                $this->access($r, $s);

                return $this->output($s);
            }abort_unless($r->user()->canDo('schedule.read.all') || $r->user()->canDo('schedule.read.own'), 403);

            return ['data' => ServiceSession::all()->filter(function ($s) use ($r) {
                try {
                    $this->access($r, $s);
                } catch (\Throwable) {
                    return false;
                }$d = $s->data;

                return (! $r->from || $d['service_date'] >= $r->from) && (! $r->to || $d['service_date'] <= $r->to) && (! $r->status || $d['status'] === $r->status) && (! $r->teacher_id || $s->assignments()->where('teacher_id', $r->teacher_id)->exists()) && (! $r->prison || str_contains($d['prison'], $r->prison)) && (! $r->q || str_contains($d['title'].' '.$d['prison'], $r->q));
            })->map(fn ($s) => $this->output($s))->values()];
        }
        if (! $id) {
            $this->permit($r, 'schedule.create.all');
        }
        $rules = ['title' => 'required|string|max:200', 'prison' => 'required|string|max:100', 'location' => 'required|string|max:200', 'participant_count' => 'required|integer|min:0', 'service_date' => 'required|date_format:Y-m-d', 'start_time' => 'required|date_format:H:i', 'end_time' => 'required|date_format:H:i|after:start_time'];
        if ($id) {
            $rules = array_map(fn ($x) => str_replace('required', 'sometimes', $x), $rules) + ['version' => 'required|integer', 'reason' => 'required|string|max:1000', 'status' => 'sometimes|in:scheduled,cancelled', 'override_conflict' => 'boolean', 'attendance_resolution' => 'nullable|in:void'];
        } else {
            $rules += ['teacher_ids' => 'required|array|min:1', 'teacher_ids.*' => 'required|integer|distinct|exists:users,id', 'repeat_weeks' => 'nullable|integer|min:1|max:52', 'override_conflict' => 'sometimes|boolean', 'reason' => 'required_if:override_conflict,true|string|max:1000'];
        }
        $v = $r->validate($rules);

        return DB::transaction(function () use ($r, $id, $v) {
            User::orderBy('id')->lockForUpdate()->get();
            $r->user()->refresh()->unsetRelation('roles');
            if (! $id) {
                $this->permit($r, 'schedule.create.all');
            }
            if ($v['override_conflict'] ?? false) {
                $this->permit($r, 'schedule.update.all');
            }
            if ($id) {
                $s = ServiceSession::lockForUpdate()->findOrFail($id);
                $before = $this->snapshot($s);
                $this->access($r, $s, 'update');
                $admin = $r->user()->canDo('schedule.update.all');
                if (! $admin) {
                    abort_unless(($s->data['original_teacher_count'] ?? $s->assignments()->count()) === 1 && $s->assignments()->where('teacher_id', $r->user()->id)->where('status', 'assigned')->exists(), 403, '共同排課須由管理員修改');
                    $this->mutable($s);
                    abort_if($v['override_conflict'] ?? false, 403);
                    abort_if(isset($v['attendance_resolution']), 403);
                    foreach (['title', 'prison', 'participant_count'] as $field) {
                        abort_if(isset($v[$field]) && $v[$field] !== $s->data[$field], 403, '此欄位須由管理員修改');
                    }
                }
                abort_unless($s->version === $v['version'], 409, '版本已更新');
                $d = array_merge($s->data, collect($v)->except(['version', 'reason', 'override_conflict', 'attendance_resolution'])->all());
                abort_unless($d['end_time'] > $d['start_time'], 422);
                if (! $admin) {
                    abort_if(now('Asia/Taipei')->gte(Carbon::parse($d['service_date'].' '.$d['end_time'], 'Asia/Taipei')), 422, '不可將排課改至已結束的時間');
                }
                $changed = $d['service_date'] !== $s->data['service_date'] || $d['start_time'] !== $s->data['start_time'] || $d['end_time'] !== $s->data['end_time'] || $d['location'] !== $s->data['location'] || $d['status'] !== $s->data['status'];
                if ($changed) {
                    DB::table('invitations')->whereIn('assignment_id', $s->assignments()->pluck('id'))->where('status', 'pending')->update(['status' => 'cancelled']);
                }
                if ($changed && $s->assignments()->whereNotNull('attendance')->exists()) {
                    abort_unless(($v['attendance_resolution'] ?? '') === 'void', 409, '請明確作廢既有簽到');
                    $s->assignments()->update(['attendance' => null]);
                }foreach ($s->assignments()->where('status', 'assigned')->get() as $a) {
                    abort_if($d['status'] === 'scheduled' && $this->conflict($a->teacher_id, $d, $s->id) && ! ($v['override_conflict'] ?? false), 409, '教師時間衝突');
                }$s->update(['data' => $d, 'version' => $s->version + 1]);
                $this->event($r, $s, '修改排課', $v['reason'], $before);

                return $this->output($s);
            }
            $out = [];
            for ($i = 0; $i < ($v['repeat_weeks'] ?? 1); $i++) {
                $d = collect($v)->except(['teacher_ids', 'repeat_weeks', 'reason', 'override_conflict'])->all();
                $d['service_date'] = Carbon::parse($v['service_date'])->addWeeks($i)->format('Y-m-d');
                $d['status'] = 'scheduled';
                $d['original_teacher_count'] = count($v['teacher_ids']);
                foreach ($v['teacher_ids'] as $teacher) {
                    $this->teacher($teacher);
                    abort_if($this->conflict($teacher, $d) && ! ($v['override_conflict'] ?? false), 409, '教師時間衝突');
                }$s = ServiceSession::create(['data' => $d]);
                foreach ($v['teacher_ids'] as $teacher) {
                    $s->assignments()->create(['teacher_id' => $teacher]);
                }$this->event($r, $s, '新增排課', $v['reason'] ?? '建立');
                $out[] = $this->output($s);
            }

            return count($out) === 1 ? $out[0] : ['data' => $out];
        });
    }

    public function assignment(Request $r, int $id, string $action)
    {
        if ($action === 'attendance') {
            return $this->attendance($r, $id);
        }
        $v = $r->validate(['version' => 'required|integer', 'reason' => 'required|string|max:1000', 'teacher_id' => in_array($action, ['invite', 'replace']) ? 'required|integer|exists:users,id' : 'nullable', 'override_conflict' => 'boolean', 'attendance_resolution' => 'nullable|in:void']);

        return DB::transaction(function () use ($r, $id, $action, $v) {
            User::orderBy('id')->lockForUpdate()->get();
            $r->user()->refresh()->unsetRelation('roles');
            $a = Assignment::findOrFail($id);
            $s = ServiceSession::lockForUpdate()->findOrFail($a->session_id);
            $before = $this->snapshot($s);
            $a->refresh();
            abort_unless($s->version === $v['version'], 409, '版本已更新');
            abort_unless($s->data['status'] === 'scheduled', 409, '排課已取消');
            $admin = $r->user()->canDo('schedule.update.all');
            abort_unless($admin || ($a->teacher_id === $r->user()->id && $r->user()->canDo('schedule.update.own')), 403);
            if (! $admin) {
                $this->mutable($s);
            }
            if (! $admin) {
                abort_if($v['override_conflict'] ?? false, 403);
            }
            if ($action === 'leave') {
                abort_unless($a->status === 'assigned' && ! $a->attendance, 409);
                $a->update(['status' => 'leave']);
            }
            if ($action === 'withdraw-leave') {
                abort_unless($a->status === 'leave', 409);
                abort_if($this->conflict($a->teacher_id, $s->data, $s->id), 409);
                $a->update(['status' => 'assigned']);
                DB::table('invitations')->where('assignment_id', $id)->where('status', 'pending')->update(['status' => 'cancelled']);
            }
            if ($action === 'invite') {
                $this->mutable($s);
                abort_unless(in_array($a->status, ['assigned', 'leave']), 409);
                $this->teacher($v['teacher_id']);
                abort_if($v['teacher_id'] === $a->teacher_id || $s->assignments()->where('teacher_id', $v['teacher_id'])->exists() || $this->conflict($v['teacher_id'], $s->data, $s->id), 409, '教師不可代課');
                abort_if(DB::table('invitations')->where('assignment_id', $id)->where('status', 'pending')->exists(), 409, '已有待回覆邀請');
                DB::table('invitations')->insert(['assignment_id' => $id, 'teacher_id' => $v['teacher_id'], 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
                Entity::create(['type' => 'notifications', 'owner_id' => $v['teacher_id'], 'data' => ['title' => '代課邀請', 'message' => $s->data['title'], 'read' => false]]);
            }
            if ($action === 'replace') {
                $this->permit($r, 'schedule.update.all');
                $this->replace($r, $a, $s, $v);
            }
            $s->increment('version');
            $this->event($r, $s, $action, $v['reason'], $before);

            return $this->output($s->fresh());
        });
    }

    private function replace(Request $r, Assignment $a, ServiceSession $s, array $v): void
    {
        $this->teacher($v['teacher_id']);
        $target = $s->assignments()->where('teacher_id', $v['teacher_id'])->first();
        abort_if($target && ($target->status !== 'replaced' || ! $r->user()->canDo('schedule.update.all')), 409, '已在名單');
        abort_if($this->conflict($v['teacher_id'], $s->data, $s->id) && ! ($v['override_conflict'] ?? false), 409, '教師時間衝突');
        if ($a->attendance) {
            abort_unless(($v['attendance_resolution'] ?? '') === 'void', 409, '請明確作廢既有簽到');
        }$a->update(['status' => 'replaced', 'attendance' => null]);
        if ($target) {
            $target->update(['status' => 'assigned', 'attendance' => null]);
        } else {
            $s->assignments()->create(['teacher_id' => $v['teacher_id']]);
        }
        DB::table('invitations')->where('assignment_id', $a->id)->where('status', 'pending')->update(['status' => 'cancelled']);
    }

    public function invitations(Request $r, ?int $id = null)
    {
        $this->permit($r, 'schedule.update.own');
        if (! $id) {
            return ['data' => DB::table('invitations')->where('teacher_id', $r->user()->id)->get()->map(function ($i) {
                $a = Assignment::find($i->assignment_id);

                return (array) $i + ['session_id' => $a?->session_id, 'session' => $a ? $this->output($a->session) : null];
            })];
        }
        $v = $r->validate(['action' => 'required|in:accept,decline']);

        return DB::transaction(function () use ($r, $id, $v) {
            User::orderBy('id')->lockForUpdate()->get();
            $r->user()->refresh()->unsetRelation('roles');
            $this->permit($r, 'schedule.update.own');
            $i = DB::table('invitations')->where('id', $id)->first();
            abort_unless($i, 404);
            $a = Assignment::findOrFail($i->assignment_id);
            $s = ServiceSession::lockForUpdate()->findOrFail($a->session_id);
            $before = $this->snapshot($s);
            $i = DB::table('invitations')->where('id', $id)->lockForUpdate()->first();
            $a->refresh();
            abort_unless($i->teacher_id === $r->user()->id, 403);
            abort_unless($i->status === 'pending', 409);
            if ($v['action'] === 'accept') {
                abort_unless(in_array($a->status, ['assigned', 'leave']) && $s->data['status'] === 'scheduled', 409);
                $this->mutable($s);
                $this->replace($r, $a, $s, ['teacher_id' => $i->teacher_id]);
            }DB::table('invitations')->where('id', $id)->update(['status' => $v['action'] === 'accept' ? 'accepted' : 'declined', 'updated_at' => now()]);
            $s->increment('version');
            $this->event($r, $s, '代課'.$v['action'], '邀請回覆', $before);

            return $this->output($s->fresh());
        });
    }

    private function attendance(Request $r, int $id)
    {
        $v = $r->validate(['photo' => 'nullable|image|mimes:jpeg,png,webp|max:5120', 'present' => 'sometimes|boolean', 'reason' => 'nullable|string|max:1000']);

        return DB::transaction(function () use ($r, $id, $v) {
            $a = Assignment::findOrFail($id);
            $s = ServiceSession::lockForUpdate()->findOrFail($a->session_id);
            $before = $this->snapshot($s);
            $a->refresh();
            $admin = $r->user()->canDo('attendance.update.all');
            abort_unless($admin || ($a->teacher_id === $r->user()->id && $r->user()->canDo('attendance.create.own')), 403);
            abort_unless($s->data['status'] === 'scheduled' && $a->status === 'assigned', 409);
            if (! $admin) {
                abort_unless(now('Asia/Taipei')->format('Y-m-d') === $s->data['service_date'], 422, '僅服務當日可簽到');
                abort_if($a->attendance, 409, '已簽到');
            } else {
                abort_unless(! empty($v['reason']), 422, '更正需要原因');
            }$photo = null;
            if ($r->hasFile('photo')) {
                $file = Entity::create(['type' => 'files', 'owner_id' => $r->user()->id, 'data' => ['path' => $r->file('photo')->store('private'), 'name' => 'attendance-photo', 'visibility' => 'private', 'assignment_id' => $a->id]]);
                $photo = $file->id;
            }$a->update(['attendance' => ['present' => $admin ? ($v['present'] ?? true) : true, 'photo_id' => $photo, 'at' => now()->toIso8601String(), 'reason' => $v['reason'] ?? null, 'actor_id' => $r->user()->id]]);
            $s->increment('version');
            $this->event($r, $s, '簽到', $v['reason'] ?? '教師簽到', $before);

            return $this->output($s->fresh());
        });
    }
}
