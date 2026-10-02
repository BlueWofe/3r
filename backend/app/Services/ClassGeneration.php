<?php

namespace App\Services;

use App\Models\ClassTemplate;
use App\Models\Entity;
use App\Models\Prison;
use App\Models\ServiceSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ClassGeneration
{
    public function generate(int $templateId, ?int $version = null, ?User $actor = null): array
    {
        return DB::transaction(function () use ($templateId, $version, $actor) {
            User::orderBy('id')->lockForUpdate()->get();
            $template = ClassTemplate::lockForUpdate()->findOrFail($templateId);
            if ($version !== null) {
                abort_unless($template->version === $version, 409, '班別版本已更新。');
            }
            if ($actor) {
                $actor->refresh()->unsetRelation('roles');
                abort_unless($actor->canDo('schedule.create.all'), 403);
            }
            $preview = app(ClassRecurrence::class)->preview($template->data);
            $result = ['created' => 0, 'existing' => 0, 'skipped' => $preview['skipped'], 'through' => $preview['through']];
            foreach ($preview['data'] as $occurrence) {
                $existing = ServiceSession::where('template_id', $templateId)->where('template_rule_id', $occurrence['rule_id'])->where('occurrence_date', $occurrence['service_date'])->exists();
                if ($existing) {
                    $result['existing']++;

                    continue;
                }
                $reason = ($template->prison_id && ! Prison::lockForUpdate()->findOrFail($template->prison_id)->active) ? '監所已停用。' : null;
                if (! $template->active) {
                    $reason = '班別已停用。';
                }
                $teachers = [];
                foreach ($template->data['teacher_ids'] as $id) {
                    $teacher = User::find($id);
                    if (! $teacher || ! $teacher->active || ! ($teacher->canDo('schedule.read.own') || $teacher->canDo('schedule.update.own'))) {
                        $reason = '教師帳號停用或已無教師權限。';
                        break;
                    }$teachers[] = $teacher;
                }
                if (! $reason) {
                    foreach ($teachers as $teacher) {
                        if (ServiceSession::where('data->status', 'scheduled')->where('data->service_date', $occurrence['service_date'])->where('data->start_time', '<', $occurrence['end_time'])->where('data->end_time', '>', $occurrence['start_time'])->whereHas('assignments', fn ($q) => $q->where('teacher_id', $teacher->id)->where('status', 'assigned'))->exists()) {
                            $reason = '教師時段衝突，未強制覆核。';
                            break;
                        }
                    }
                }
                if ($reason) {
                    $result['skipped'][] = ['service_date' => $occurrence['service_date'], 'rule_id' => $occurrence['rule_id'], 'reason' => $reason];

                    continue;
                }
                $data = ['title' => $template->data['name'], 'prison' => $template->prisonData()['prison'], 'prison_id' => $template->prison_id, 'location' => $template->data['location'], 'participant_count' => $template->data['participant_count'], 'service_date' => $occurrence['service_date'], 'start_time' => $occurrence['start_time'], 'end_time' => $occurrence['end_time'], 'status' => 'scheduled', 'original_teacher_count' => max(1, count($teachers)), 'template_id' => $templateId, 'rule_id' => $occurrence['rule_id'], 'occurrence_date' => $occurrence['service_date']];
                $data['class_name'] = $template->data['name'];
                $data['color'] = $template->data['color'] ?? '#3d8768';
                $s = ServiceSession::create(['data' => $data, 'template_id' => $templateId, 'template_rule_id' => $occurrence['rule_id'], 'occurrence_date' => $occurrence['service_date']]);
                foreach ($teachers as $teacher) {
                    $s->assignments()->create(['teacher_id' => $teacher->id]);
                }
                Entity::create(['type' => 'changes', 'owner_id' => $actor?->id, 'data' => ['session_id' => $s->id, 'action' => '班別產生排課', 'reason' => '班別 '.$template->data['name'], 'version' => 1, 'actor' => $actor?->name ?? '每日排程', 'acknowledged_by' => [], 'before' => [], 'after' => $data + ['version' => 1, 'assignments' => $s->assignments()->get()->toArray()]]]);
                $systemAdmins = User::where('active', true)->whereHas('roles', fn ($q) => $q->where('slug', 'system-admin')->where('roles.active', true))->pluck('id');
                $recipients = collect($teachers)->pluck('id')->merge($systemAdmins)->unique();
                foreach ($recipients as $recipient) {
                    Entity::create(['type' => 'notifications', 'owner_id' => $recipient, 'data' => ['title' => $data['title'], 'message' => '班別產生排課', 'session_id' => $s->id, 'read' => false]]);
                    $line = Entity::where('type', 'line')->where('owner_id', $recipient)->first();
                    if (($line?->data['bound'] ?? false) && ($line?->data['subscribed'] ?? false)) {
                        Entity::create(['type' => 'line-outbox', 'owner_id' => $recipient, 'data' => ['mode' => 'mock', 'message' => '班別產生排課：'.$data['title'], 'delivered' => false]]);
                    }
                }
                $result['created']++;
            }
            Entity::create(['type' => 'audit', 'owner_id' => $actor?->id, 'data' => ['module' => 'class-templates', 'subject_id' => $templateId, 'action' => 'generate', 'template_version' => $template->version, 'template' => $template->publicData(), 'result' => $result]]);

            return $result;
        });
    }
}
