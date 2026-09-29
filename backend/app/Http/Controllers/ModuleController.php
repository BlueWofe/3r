<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Entity;
use App\Models\ServiceSession;
use App\Models\User;
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
                $allowed = $e->owner_id === $r->user()->id || $r->user()->canDo('resources.read.all');
                if (isset($d['resource_id'])) {
                    try {
                        $this->entity($r, 'resources', $d['resource_id']);
                        $allowed = true;
                    } catch (\Throwable) {
                    }
                }if (isset($d['assignment_id'])) {
                    $a = Assignment::find($d['assignment_id']);
                    $allowed = $allowed || ($a && ($a->teacher_id === $r->user()->id || $r->user()->canDo('attendance.update.all')));
                }foreach (Entity::where('type', 'meetings')->get() as $m) {
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
        $v = $r->validate(['file' => 'required|file|max:20480|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,webp,txt,csv', 'visibility' => 'required|in:public,private', 'title' => 'nullable|string|max:200', 'category' => 'nullable|string|max:100']);
        abort_unless($r->user()->canDo('resources.create.own') || $r->user()->canDo('resources.create.all') || $r->user()->canDo('content.create.all') || $r->user()->canDo('meetings.create.all'), 403);
        if ($v['visibility'] === 'public') {
            $this->permit($r, 'content.publish.all');
        }$e = Entity::create(['type' => 'files', 'owner_id' => $r->user()->id, 'data' => ['path' => $r->file('file')->store('files'), 'name' => $r->file('file')->getClientOriginalName(), 'visibility' => $v['visibility'], 'category' => $v['category'] ?? null, 'title' => $v['title'] ?? null]]);

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
        abort_unless($r->user()->canDo('resources.create.own') || $r->user()->canDo('resources.create.all'), 403);
        $v = $r->validate(['file' => 'required|file|max:20480|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,webp,txt,csv', 'title' => 'required|string|max:200', 'category' => 'required|string|max:100', 'role_ids' => 'nullable|array', 'role_ids.*' => 'exists:roles,id']);
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
        $e = $this->entity($r, 'cases', $id, 'update');
        $v = $r->validate(['service_date' => 'required|date', 'type' => 'required|string|max:100', 'summary' => 'required|string|max:10000', 'follow_up' => 'nullable|string|max:10000']);
        $d = $e->data;
        $d['records'][] = $v + ['author_id' => $r->user()->id, 'created_at' => now()->toIso8601String()];
        $e->update(['data' => $d]);

        return $e->publicData();
    }

    public function responses(Request $r, int $id, bool $export = false)
    {
        $f = $this->entity($r, 'forms', $id);
        if ($r->isMethod('get')) {
            $this->permit($r, $export ? 'forms.export.all' : 'forms.read.all');
            $data = Entity::where('type', 'responses')->get()->filter(fn ($e) => $e->data['form_id'] === $id)->map->publicData()->values();
            if ($export) {
                return response()->streamDownload(function () use ($data) {
                    $out = fopen('php://output', 'w');
                    fputcsv($out, ['ID', '填答者', '版本', '答案']);
                    foreach ($data as $row) {
                        $answers = json_encode($row['answers'], JSON_UNESCAPED_UNICODE);
                        fputcsv($out, [$row['id'], $row['owner_id'], $row['version'], $answers]);
                    }fclose($out);
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
            if ($field['type'] === 'select') {
                $rule[] = Rule::in($field['options'] ?? []);
            }$rules['answers.'.$field['key']] = $rule;
            if ($field['type'] === 'multiselect') {
                $rules['answers.'.$field['key'].'.*'] = [Rule::in($field['options'] ?? [])];
            }if ($field['type'] === 'file' && isset($answers[$field['key']])) {
                $file = Entity::where('type', 'files')->findOrFail($answers[$field['key']]);
                abort_unless($file->owner_id === $r->user()->id, 403);
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
                abort_unless($r->user()->canDo('schedule.read.all') || $s->assignments()->where('teacher_id', $r->user()->id)->exists(), 403);
                $d['acknowledged_by'] = array_values(array_unique(array_merge($d['acknowledged_by'] ?? [], [$r->user()->id])));
            }$e->update(['data' => $d]);

            return $e->publicData();
        }

        return ['data' => Entity::where('type', $type)->get()->filter(function ($e) use ($r, $type) {
            if ($type === 'notifications') {
                return $e->owner_id === $r->user()->id;
            }$s = ServiceSession::find($e->data['session_id']);

            return $r->user()->canDo('schedule.read.all') || ($s && $s->assignments()->where('teacher_id', $r->user()->id)->exists());
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
        $e = Entity::firstOrCreate(['type' => 'settings'], ['data' => ['association_name' => '示範監獄福音協會', 'contact_phone' => '', 'contact_email' => '', 'address' => '']]);
        if (! $r->isMethod('get')) {
            $e->update(['data' => $r->validate(['association_name' => 'required|string|max:200', 'contact_phone' => 'nullable|string|max:100', 'contact_email' => 'nullable|email', 'address' => 'nullable|string|max:500'])]);
        }

return $e->data;
    }

    public function reports(Request $r)
    {
        $all = $r->user()->canDo('reports.read.all');
        abort_unless($all || $r->user()->canDo('reports.read.own'), 403);
        $teachers = User::where('active', true)->get()->filter(fn ($u) => ($all || $u->id === $r->user()->id) && $u->canDo('schedule.read.own'));
        $rows = $teachers->map(function ($u) {
            $as = Assignment::where('teacher_id', $u->id)->with('session')->get();

            return ['teacher_id' => $u->id, 'name' => $u->name, 'assigned' => $as->where('status', 'assigned')->count(), 'leave' => $as->where('status', 'leave')->count(), 'replaced' => $as->where('status', 'replaced')->count(), 'attended' => $as->filter(fn ($a) => ($a->attendance['present'] ?? false))->count(), 'hours' => $as->filter(fn ($a) => ($a->attendance['present'] ?? false))->sum(fn ($a) => Carbon::parse($a->session->data['start_time'])->diffInMinutes(Carbon::parse($a->session->data['end_time'])) / 60)];
        })->values();

        return ['summary' => ['teacher_count' => $rows->count(), 'attendance_count' => $rows->sum('attended'), 'service_hours' => $rows->sum('hours')], 'teachers' => $rows, 'data' => $rows];
    }
}
