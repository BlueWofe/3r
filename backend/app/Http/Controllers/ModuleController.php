<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Entity;
use App\Models\ServiceSession;
use App\Models\User;
use App\Services\GroupAudience;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ModuleController extends ApiController
{
    public function files(Request $r, ?int $id = null)
    {
        if ($id) {
            $e = Entity::where('type', 'files')->findOrFail($id);
            $d = $e->data;
            $allowed = ($d['visibility'] ?? 'private') === 'public';
            if (! $allowed && $r->user()) {
                // Read-all grants access through a linked resource, never to every private file.
                $allowed = $e->owner_id === $r->user()->id;
                if (isset($d['resource_id'])) {
                    $allowed = false;
                    try {
                        $this->entity($r, 'resources', $d['resource_id']);
                        $allowed = true;
                    } catch (\Throwable) {
                    }
                }
                if (isset($d['assignment_id'])) {
                    $a = Assignment::find($d['assignment_id']);
                    $allowed = $allowed || ($a && (($a->teacher_id === $r->user()->id && $r->user()->canDo('attendance.create.own')) || $r->user()->canDo('attendance.update.all')));
                }
                if (isset($d['form_id'])) {
                    try {
                        $this->entity($r, 'forms', (int) $d['form_id']);
                        $allowed = true;
                    } catch (\Throwable) {
                    }
                }
                foreach (isset($d['resource_id']) ? [] : Entity::where('type', 'meetings')->get() as $m) {
                    if (in_array($id, $m->data['file_ids'] ?? [])) {
                        try {
                            $this->entity($r, 'meetings', $m->id);
                            $allowed = true;
                        } catch (\Throwable) {
                        }
                    }
                }
            }abort_unless($allowed, 403);
            abort_unless(Storage::disk('local')->exists($d['path']), 404);

            return Storage::disk('local')->download($d['path'], $d['name'], ['X-Content-Type-Options' => 'nosniff']);
        }
        $v = $r->validate([
            'file' => 'required|file|max:20480|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,webp,txt,csv',
            'visibility' => [Rule::requiredIf(fn () => ! $r->filled('form_id')), 'nullable', 'in:public,private'],
            'title' => 'nullable|string|max:200',
            'category' => 'nullable|string|max:100',
            'form_id' => 'nullable|integer|exists:entities,id',
            'field_key' => 'required_with:form_id|nullable|string|alpha_dash',
        ]);
        $form = null;
        if (! empty($v['form_id'])) {
            $form = $this->entity($r, 'forms', (int) $v['form_id']);
            abort_unless(($form->data['status'] ?? null) === 'published', 422, '表單尚未發布');
            $fileField = collect($form->data['fields'] ?? [])->first(fn ($field) => ($field['key'] ?? null) === $v['field_key'] && ($field['type'] ?? null) === 'file');
            abort_unless($fileField, 422, '欄位不是檔案欄位');
            abort_if(($v['visibility'] ?? 'private') !== 'private', 422, '表單回覆檔案必須為私人檔案');
        } else {
            abort_unless(array_key_exists('visibility', $v), 422, '請指定檔案可見範圍');
            abort_unless($r->user()->canDo('resources.create.own') || $r->user()->canDo('resources.create.all') || $r->user()->canDo('content.create.all') || $r->user()->canDo('meetings.create.all'), 403);
        }
        if (($v['visibility'] ?? 'private') === 'public') {
            $this->permit($r, 'content.publish.all');
        }
        $data = [
            'path' => $r->file('file')->store('files'),
            'name' => $r->file('file')->getClientOriginalName(),
            'visibility' => $form ? 'private' : $v['visibility'],
            'category' => $v['category'] ?? null,
            'title' => $v['title'] ?? null,
        ];
        if ($form) {
            $data['form_id'] = $form->id;
            $data['form_field'] = $v['field_key'];
        }
        $e = Entity::create(['type' => 'files', 'owner_id' => $r->user()->id, 'data' => $data]);

        return ['id' => $e->id, 'name' => $e->data['name'], 'url' => '/api/v1/files/'.$e->id.'/download'];
    }

    public function resources(Request $r, ?int $id = null)
    {
        if ($r->isMethod('get')) {
            return $this->generic($r, 'resources');
        }
        if ($r->isMethod('delete')) {
            return $this->generic($r, 'resources', $id);
        }
        if ($r->isMethod('put')) {
            return DB::transaction(function () use ($r, $id) {
                $this->permit($r, 'resources.update.all');
                $e = Entity::where('type', 'resources')->lockForUpdate()->findOrFail($id);
                $v = $r->validate(['title' => 'sometimes|string|max:200', 'category' => 'sometimes|string|max:100', 'role_ids' => 'sometimes|array|max:100', 'role_ids.*' => 'exists:roles,id', 'group_ids' => 'sometimes|array|max:100', 'group_ids.*' => 'integer']);
                if (array_key_exists('group_ids', $v)) {
                    $v['group_ids'] = app(GroupAudience::class)->validate($v['group_ids']);
                }
                $before = $e->data;
                $e->update(['data' => array_merge($before, $v)]);
                Entity::create(['type' => 'audit', 'owner_id' => $r->user()->id, 'data' => ['module' => 'resources', 'subject_id' => $id, 'action' => 'update', 'before' => $before, 'after' => $e->data]]);

                return $e->publicData();
            });
        }
        abort_unless($r->user()->canDo('resources.create.own') || $r->user()->canDo('resources.create.all'), 403);
        $v = $r->validate(['file' => 'required|file|max:20480|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,webp,txt,csv', 'title' => 'required|string|max:200', 'category' => 'required|string|max:100', 'role_ids' => 'nullable|array', 'role_ids.*' => 'exists:roles,id', 'group_ids' => 'sometimes|array|max:100', 'group_ids.*' => 'integer']);
        $v['group_ids'] = app(GroupAudience::class)->validate($v['group_ids'] ?? []);
        if (! $r->user()->canDo('resources.create.all')) {
            abort_if(count(array_diff($v['group_ids'], app(GroupAudience::class)->membershipIds($r->user()))) > 0, 403);
        }
        if (! empty($v['role_ids'])) {
            $this->permit($r, 'resources.create.all');
        }
        $e = Entity::create(['type' => 'resources', 'owner_id' => $r->user()->id, 'data' => collect($v)->except('file')->all()]);
        $f = Entity::create(['type' => 'files', 'owner_id' => $r->user()->id, 'data' => ['path' => $r->file('file')->store('resources'), 'name' => $r->file('file')->getClientOriginalName(), 'visibility' => 'private', 'resource_id' => $e->id]]);
        $e->update(['data' => $e->data + ['file_id' => $f->id, 'url' => '/api/v1/files/'.$f->id.'/download']]);

        return $e->publicData();
    }

    public function records(Request $r, int $id)
    {
        $v = $r->validate(['service_date' => 'required|date', 'type' => 'required|string|max:100', 'summary' => 'required|string|max:10000', 'follow_up' => 'nullable|string|max:10000']);

        return DB::transaction(function () use ($r, $id, $v) {
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $r->user()->refresh()->unsetRelation('roles');
            $e = Entity::where('type', 'cases')->lockForUpdate()->findOrFail($id);
            $this->entity($r, 'cases', $id, 'update');
            $before = $e->data['records'] ?? [];
            $after = [...$before, $v + ['id' => (string) Str::uuid(), 'author_id' => $r->user()->id, 'created_at' => now()->toIso8601String()]];
            $data = $e->data;
            $data['records'] = $after;
            $e->update(['data' => $data]);
            Entity::create(['type' => 'audit', 'owner_id' => $r->user()->id, 'data' => [
                'module' => 'cases',
                'action' => 'record.created',
                'subject_id' => $e->id,
                'before' => $before,
                'after' => $after,
            ]]);

            return $e->fresh()->publicData();
        });
    }

    public function responses(Request $r, int $id, bool $export = false)
    {
        $f = $this->entity($r, 'forms', $id);
        if ($r->isMethod('get')) {
            $this->permit($r, $export ? 'forms.export.all' : 'forms.read.all');
            $data = Entity::where('type', 'responses')->get()->filter(fn ($e) => $e->data['form_id'] === $id)->map->publicData()->values();
            if ($export) {
                $snapshots = $f->data['snapshots'] ?? [];

                return response()->streamDownload(function () use ($data, $snapshots) {
                    $out = fopen('php://output', 'w');
                    fputcsv($out, ['ID', '填答者', '版本', '欄位快照', '答案']);
                    foreach ($data as $row) {
                        $snapshot = collect($snapshots)->firstWhere('version', $row['version']);
                        $fields = json_encode($this->safeCsvValue($snapshot['fields'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $answers = json_encode($this->safeCsvValue($row['answers'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        fputcsv($out, [$row['id'], $row['owner_id'], $row['version'], $this->safeCsvCell($fields), $this->safeCsvCell($answers)]);
                    }
                    fclose($out);
                }, 'form-'.$id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
            }

            return ['data' => $data];
        }
        abort_unless($f->data['status'] === 'published', 422, '表單尚未發布');
        abort_if(! empty($f->data['deadline']) && now('Asia/Taipei')->gt($f->data['deadline'].' 23:59:59'), 422, '已截止');
        $r->validate(['answers' => 'required|array']);
        $answers = $r->input('answers');
        $fields = collect($f->data['fields']);
        abort_if(array_diff(array_keys($answers), $fields->pluck('key')->all()), 422, '包含未知欄位');
        $rules = [];
        foreach ($fields as $field) {
            $rule = [$field['required'] ? 'required' : 'nullable'];
            $rule[] = match ($field['type']) {
                'number' => 'numeric','date' => 'date','multiselect' => 'array','file' => 'integer',default => 'string'
            };
            if ($field['type'] === 'file') {
                $rule[] = 'exists:entities,id';
            }
            if ($field['type'] === 'select') {
                $rule[] = Rule::in($field['options'] ?? []);
            }$rules['answers.'.$field['key']] = $rule;
            if ($field['type'] === 'multiselect') {
                $rules['answers.'.$field['key'].'.*'] = [Rule::in($field['options'] ?? [])];
            }if ($field['type'] === 'file' && isset($answers[$field['key']])) {
                $file = Entity::where('type', 'files')->find($answers[$field['key']]);
                abort_unless($file
                    && $file->owner_id === $r->user()->id
                    && ($file->data['form_id'] ?? null) === $id
                    && ($file->data['form_field'] ?? null) === $field['key']
                    && ($file->data['visibility'] ?? 'private') === 'private', 422, '檔案必須先上傳至此表單的指定欄位');
            }
        }$r->validate($rules);
        $e = Entity::create(['type' => 'responses', 'owner_id' => $r->user()->id, 'data' => ['form_id' => $id, 'version' => $f->data['version'], 'answers' => $answers]]);

        return $e->publicData();
    }

    public function donations(Request $r, ?int $id = null)
    {
        if ($r->isMethod('get')) {
            return $this->generic($r, 'donations');
        }
        if ($id) {
            $v = $r->validate(['result' => 'required|in:success,failed,cancelled']);

            return DB::transaction(function () use ($r, $id, $v) {
                $e = Entity::where('type', 'donations')->lockForUpdate()->findOrFail($id);
                abort_unless($e->owner_id === $r->user()->id, 403);
                if ($e->data['status'] !== 'pending') {
                    return $e->publicData();
                }$e->update(['data' => array_merge($e->data, ['status' => $v['result'], 'simulated_at' => now()->toIso8601String()])]);

                return $e->publicData();
            });
        }
        $v = $r->validate(['amount' => 'required|integer|min:1|max:1000000', 'purpose' => 'required|string|max:200']);
        $e = Entity::create(['type' => 'donations', 'owner_id' => $r->user()->id, 'data' => $v + ['status' => 'pending', 'provider' => 'mock', 'reference' => (string) Str::uuid()]]);

        return $e->publicData();
    }

    public function inbox(Request $r, string $type, ?int $id = null)
    {
        if ($id) {
            $e = Entity::where('type', $type)->findOrFail($id);
            $d = $e->data;
            if ($type === 'notifications') {
                abort_unless($e->owner_id === $r->user()->id, 403);
                $d['read'] = true;
            } else {
                $s = ServiceSession::findOrFail($d['session_id']);
                $canReadAll = $r->user()->canDo('schedule.read.all');
                $canReadOwn = $r->user()->canDo('schedule.read.own') && $s->assignments()->where('teacher_id', $r->user()->id)->exists();
                abort_unless($canReadAll || $canReadOwn, 403);
                $d['acknowledged_by'] = array_values(array_unique(array_merge($d['acknowledged_by'] ?? [], [$r->user()->id])));
            }$e->update(['data' => $d]);

            return $e->publicData();
        }

        return ['data' => Entity::where('type', $type)->get()->filter(function ($e) use ($r, $type) {
            if ($type === 'notifications') {
                return $e->owner_id === $r->user()->id;
            }$s = ServiceSession::find($e->data['session_id']);

            return $r->user()->canDo('schedule.read.all')
                || ($r->user()->canDo('schedule.read.own') && $s && $s->assignments()->where('teacher_id', $r->user()->id)->exists());
        })->map->publicData()->values()];
    }

    public function integrations(Request $r, string $type)
    {
        if ($type === 'drive') {
            abort_unless($r->user()->canDo('resources.read.own') || $r->user()->canDo('resources.read.all'), 403);
            if ($r->isMethod('get')) {
                return ['mode' => 'mock', 'data' => [['id' => 'demo', 'name' => '示範共用資料夾']]];
            }$v = $r->validate(['action' => 'required|in:upload,download', 'name' => 'required|string|max:200']);
            if ($v['action'] === 'upload') {
                abort_unless($r->user()->canDo('resources.create.own') || $r->user()->canDo('resources.create.all'), 403);
            }

            return ['mode' => 'mock', 'status' => 'simulated'] + $v;
        }
        $e = Entity::where('type', 'line')->where('owner_id', $r->user()->id)->first();
        if (! $e) {
            $e = Entity::create(['type' => 'line', 'owner_id' => $r->user()->id, 'data' => ['bound' => false, 'subscribed' => false, 'mode' => 'mock']]);
        }if (! $r->isMethod('get')) {
            $v = $r->validate(['bound' => 'required|boolean', 'subscribed' => 'required|boolean']);
            abort_if(! $v['bound'] && $v['subscribed'], 422, '需先綁定');
            $e->update(['data' => $v + ['mode' => 'mock']]);
        }

        return $e->data;
    }

    public function settings(Request $r)
    {
        $this->permit($r, 'settings.manage.all');
        $e = Entity::firstOrCreate(['type' => 'settings'], ['data' => ['association_name' => '中華復甦更新發展協會', 'contact_phone' => '', 'contact_email' => '', 'address' => '']]);
        if (! $r->isMethod('get')) {
            $e->update(['data' => $r->validate(['association_name' => 'required|string|max:200', 'contact_phone' => 'nullable|string|max:100', 'contact_email' => 'nullable|email', 'address' => 'nullable|string|max:500'])]);
        }

        return $e->data;
    }

    public function reports(Request $r)
    {
        $all = $r->user()->canDo('reports.read.all');
        abort_unless($all || $r->user()->canDo('reports.read.own'), 403);
        $filters = $r->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'teacher_id' => 'nullable|integer|exists:users,id',
        ]);
        abort_if(isset($filters['from'], $filters['to']) && $filters['to'] < $filters['from'], 422, '結束日期不可早於開始日期');
        if (! $all && isset($filters['teacher_id'])) {
            abort_unless((int) $filters['teacher_id'] === $r->user()->id, 403);
        }

        $start = $filters['from'] ?? '0001-01-01';
        $end = min($filters['to'] ?? now('Asia/Taipei')->toDateString(), now('Asia/Taipei')->toDateString());
        $inRange = ServiceSession::all()->filter(function ($session) use ($start, $end) {
            $date = $session->data['service_date'] ?? null;

            return $date && $date >= $start && $date <= $end;
        })->keyBy('id');
        $scopeTeacherId = ! $all ? $r->user()->id : (isset($filters['teacher_id']) ? (int) $filters['teacher_id'] : null);
        if ($scopeTeacherId !== null) {
            $scopedSessionIds = Assignment::where('teacher_id', $scopeTeacherId)
                ->whereIn('session_id', $inRange->keys())
                ->pluck('session_id')
                ->all();
            $inRange = $inRange->only($scopedSessionIds);
        }
        $cancelledSessions = $inRange->filter(fn ($session) => ($session->data['status'] ?? null) === 'cancelled');
        $sessions = $inRange->reject(fn ($session) => ($session->data['status'] ?? null) === 'cancelled')
            ->filter(function ($session) {
                $date = $session->data['service_date'] ?? null;
                $endTime = $session->data['end_time'] ?? null;

                return $date && $endTime && Carbon::parse($date.' '.$endTime, 'Asia/Taipei')->lt(now('Asia/Taipei'));
            });
        $teachers = User::where('active', true)->get()->filter(function ($user) use ($all, $r, $filters) {
            return ($all || $user->id === $r->user()->id)
                && (! isset($filters['teacher_id']) || $user->id === (int) $filters['teacher_id'])
                && $user->canDo('schedule.read.own');
        });
        $rows = $teachers->map(function ($user) use ($sessions) {
            $assignments = Assignment::where('teacher_id', $user->id)->with('session')->get()
                ->filter(fn ($assignment) => $assignment->session && $sessions->contains('id', (int) $assignment->session_id));
            $completed = $assignments->filter(fn ($assignment) => $assignment->status === 'assigned');
            $attended = $completed->filter(fn ($assignment) => ($assignment->attendance['present'] ?? false));
            $denominator = $completed->count();

            return [
                'teacher_id' => $user->id,
                'name' => $user->name,
                'assigned' => $denominator,
                'leave' => $assignments->where('status', 'leave')->count(),
                'replaced' => $assignments->where('status', 'replaced')->count(),
                'attended' => $attended->count(),
                'attendance_rate' => $denominator === 0 ? null : round($attended->count() / $denominator, 4),
                'completed_sessions' => $completed->pluck('session_id')->unique()->count(),
                'hours' => $attended->sum(fn ($assignment) => Carbon::parse($assignment->session->data['start_time'])->diffInMinutes(Carbon::parse($assignment->session->data['end_time'])) / 60),
            ];
        })->values();

        $vacancies = $sessions->sum(function ($session) {
            $assignments = Assignment::where('session_id', $session->id)->get();
            $required = (int) ($session->data['original_teacher_count'] ?? max(1, $assignments->where('status', '!=', 'replaced')->count()));
            $assigned = $assignments->where('status', 'assigned')->count();

            return max(0, $required - $assigned);
        });
        $denominator = $rows->sum('assigned');
        $summary = [
            'teacher_count' => $rows->count(),
            'completed_sessions' => $sessions->count(),
            'cancelled_sessions' => $cancelledSessions->count(),
            'vacancies' => $vacancies,
            'assigned_denominator' => $denominator,
            'attendance_count' => $rows->sum('attended'),
            'attendance_rate' => $denominator === 0 ? null : round($rows->sum('attended') / $denominator, 4),
            'leave_count' => $rows->sum('leave'),
            'replaced_count' => $rows->sum('replaced'),
            'service_hours' => $rows->sum('hours'),
        ];

        // Financial and association-wide usage values are only available to all-scope readers.
        if ($all) {
            $summary['product_views'] = Entity::where('type', 'contents')->get()->filter(fn ($content) => ($content->data['kind'] ?? null) === 'product' && ($content->data['status'] ?? null) === 'published')->sum(fn ($content) => (int) ($content->data['views'] ?? 0));
        }
        if ($all && $r->user()->canDo('donations.read.all')) {
            $summary['successful_test_donations_sum'] = Entity::where('type', 'donations')->get()->filter(function ($donation) use ($start, $end) {
                $date = substr((string) ($donation->data['simulated_at'] ?? $donation->created_at), 0, 10);

                return ($donation->data['status'] ?? null) === 'success'
                    && in_array($donation->data['provider'] ?? null, ['mock', 'test'], true)
                    && $date >= $start && $date <= $end;
            })->sum(fn ($donation) => (int) ($donation->data['amount'] ?? 0));
        }

        return ['summary' => $summary, 'teachers' => $rows, 'data' => $rows, 'filters' => ['from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null, 'teacher_id' => isset($filters['teacher_id']) ? (int) $filters['teacher_id'] : null]];
    }

    private function safeCsvCell(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[\s\x00-\x1f]*[=+\-@]/u', $value) === 1 ? "'".$value : $value;
    }

    private function safeCsvValue(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->safeCsvValue($item);
            }

            return $value;
        }

        return is_string($value) ? $this->safeCsvCell($value) : $value;
    }
}
