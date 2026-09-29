<?php

namespace App\Http\Controllers;

use App\Models\ClassTemplate;
use App\Models\Entity;
use App\Models\User;
use App\Services\ClassGeneration;
use App\Services\ClassRecurrence;
use App\Services\PrisonDirectory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ClassTemplateController extends ApiController
{
    private function validated(Request $r, bool $update = false): array
    {
        $v = $r->validate(['name' => 'required|string|max:200', 'prison' => 'required_without:prison_id|string|max:100', 'prison_id' => 'required_without:prison|integer|exists:prisons,id', 'location' => 'required|string|max:200', 'participant_count' => 'required|integer|min:0|max:1000000', 'teacher_ids' => 'present|array|max:20', 'teacher_ids.*' => 'integer|distinct|exists:users,id', 'active' => 'required|boolean', 'start_date' => 'required|date_format:Y-m-d', 'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date', 'rules' => 'required|array|min:1|max:12', 'rules.*.id' => 'required|string|max:100|distinct', 'rules.*.frequency' => 'required|in:weekly,monthly_date,monthly_weekday', 'rules.*.start_time' => 'required|date_format:H:i', 'rules.*.end_time' => 'required|date_format:H:i', 'rules.*.weekdays' => 'sometimes|array|max:7', 'rules.*.weekdays.*' => 'integer|between:1,7', 'rules.*.month_day' => 'sometimes|integer|between:1,31', 'rules.*.week_of_month' => 'sometimes|integer|in:-1,1,2,3,4,5', 'rules.*.weekday' => 'sometimes|integer|between:1,7', 'version' => $update ? 'required|integer|min:1' : 'sometimes|integer|min:1']);
        $rules = [];
        foreach ($v['rules'] as $i => $rule) {
            $fields = match ($rule['frequency']) {
                'weekly' => ['weekdays' => 'required|array|min:1|max:7', 'weekdays.*' => 'required|integer|between:1,7|distinct'],
                'monthly_date' => ['month_day' => 'required|integer|between:1,31'],
                'monthly_weekday' => ['week_of_month' => 'required|integer|in:-1,1,2,3,4,5', 'weekday' => 'required|integer|between:1,7'],
            };
            $validator = Validator::make($rule, $fields);
            if ($validator->fails()) {
                $errors = [];
                foreach ($validator->errors()->messages() as $field => $messages) {
                    $errors["rules.$i.$field"] = $messages;
                }throw ValidationException::withMessages($errors);
            }
            if ($rule['end_time'] <= $rule['start_time']) {
                throw ValidationException::withMessages(["rules.$i.end_time" => '結束時間必須晚於開始時間。']);
            }
            $clean = array_intersect_key($rule, array_flip(['id', 'frequency', 'start_time', 'end_time']));
            foreach ($validator->validated() as $field => $value) {
                $clean[$field] = is_array($value) ? array_map('intval', $value) : (int) $value;
            }
            $rules[] = $clean;
        }
        $v['rules'] = $rules;
        $v['teacher_ids'] = array_map('intval', $v['teacher_ids']);
        $v['participant_count'] = (int) $v['participant_count'];
        $v['active'] = (bool) $v['active'];
        $v['end_date'] = $v['end_date'] ?? null;

        return $v;
    }

    public function templates(Request $r, ?int $id = null)
    {
        if ($r->isMethod('get')) {
            $this->permit($r, 'schedule.read.all');

            return $id ? ClassTemplate::findOrFail($id)->publicData() : ['data' => ClassTemplate::orderBy('id')->get()->map->publicData()];
        }
        $permission = $id ? 'schedule.update.all' : 'schedule.create.all';
        $this->permit($r, $permission);
        $v = $this->validated($r, (bool) $id);

        return DB::transaction(function () use ($r, $id, $v, $permission) {
            User::orderBy('id')->lockForUpdate()->get();
            $r->user()->refresh()->unsetRelation('roles');
            $this->permit($r, $permission);
            foreach ($v['teacher_ids'] as $teacherId) {
                $teacher = User::find($teacherId);
                if (! $teacher || ! $teacher->active || ! ($teacher->canDo('schedule.read.own') || $teacher->canDo('schedule.update.own'))) {
                    throw ValidationException::withMessages(['teacher_ids' => '請選擇啟用且具教師權限的帳號。']);
                }
            }
            $template = $id ? ClassTemplate::lockForUpdate()->findOrFail($id) : new ClassTemplate;
            $before = $id ? $template->publicData() : [];
            if ($id) {
                abort_unless($template->version === (int) $v['version'], 409, '班別版本已更新。');
            }
            if ($template->prison_id && ! array_key_exists('prison_id', $v) && ($v['prison'] ?? null) === ($template->data['prison'] ?? null)) {
                $v['prison_id'] = $template->prison_id;
            }
            $data = array_merge(collect($v)->except('version')->all(), app(PrisonDirectory::class)->resolve($v, $template->prison_id, $r->user()->canDo('schedule.create.all') || $r->user()->canDo('prisons.manage.all')));
            $template->data = $data;
            $template->active = $v['active'];
            if ($id) {
                $template->version++;
            }$template->save();
            Entity::create(['type' => 'audit', 'owner_id' => $r->user()->id, 'data' => ['module' => 'class-templates', 'subject_id' => $template->id, 'action' => $id ? 'update' : 'create', 'before' => $before, 'after' => $template->publicData()]]);

            return $template->publicData();
        });
    }

    public function preview(Request $r): array
    {
        abort_unless($r->user()->canDo('schedule.create.all') || $r->user()->canDo('schedule.update.all'), 403);

        return app(ClassRecurrence::class)->preview($this->validated($r));
    }

    public function generate(Request $r, int $id): array
    {
        $this->permit($r, 'schedule.create.all');
        $v = $r->validate(['version' => 'required|integer|min:1']);

        return app(ClassGeneration::class)->generate($id, (int) $v['version'], $r->user());
    }
}
