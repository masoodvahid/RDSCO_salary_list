<?php

namespace App\Services;

use App\Enums\ReviewStatus;
use App\Enums\Stage;
use App\Models\ChangeLog;
use App\Models\Project;
use App\Models\Sheet;
use App\Models\SheetColumn;
use App\Models\SheetRow;
use App\Models\User;
use App\Models\UserLog;
use App\Support\Digits;
use App\Support\Jalali;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * A member's activity for the members page: account events (user_logs) merged with what the
 * member did inside payroll lists (change_logs), newest first, as readable Persian lines.
 */
final class UserActivity
{
    public const FILTERS = ['all' => 'همه', 'account' => 'ورود و حساب کاربری', 'lists' => 'کار در لیست‌های حقوق'];

    private const ROW_FIELDS = ['first_name' => 'نام', 'last_name' => 'نام خانوادگی', 'personnel_code' => 'کد پرسنلی', 'national_code' => 'کد ملی'];

    public function record(User $user, string $action, ?User $actor = null, ?array $meta = null): UserLog
    {
        $request = app()->runningInConsole() ? null : request();

        return UserLog::create([
            'user_id' => $user->id,
            'actor_id' => $actor?->id,
            'action' => $action,
            'meta' => $meta,
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent() ? mb_substr($request->userAgent(), 0, 255) : null,
        ]);
    }

    /**
     * @return array{entries: list<array{at: Carbon, kind: string, tone: string, text: string, context: ?string, detail: ?string}>, more: bool}
     */
    public function feed(User $member, int $limit = 50, string $filter = 'all'): array
    {
        $limit = max(10, min($limit, 1000));
        $entries = collect();

        if ($filter !== 'lists') {
            $logs = UserLog::query()
                ->where(fn ($q) => $q->where('user_id', $member->id)->orWhere('actor_id', $member->id))
                ->with(['user:id,name', 'actor:id,name'])
                ->latest('id')
                ->limit($limit + 1)
                ->get();
            $entries = $entries->merge($logs->map(fn (UserLog $log) => $this->describeAccount($log, $member)));
        }

        if ($filter !== 'account') {
            $changes = ChangeLog::query()
                ->where('user_id', $member->id)
                // Cells written by an Excel import are summed up by its own import line, except changes to a list
                // that was already approved: those are listed one by one.
                // (Written without NOT: a JSON lookup on an empty meta is NULL, which NOT would turn into "skip".)
                ->where(fn ($q) => $q->where('action', '!=', 'cell.update')
                    ->orWhereNull('meta')
                    ->orWhereNull('meta->source')
                    ->orWhereNotNull('meta->after_approval'))
                ->latest('id')
                ->limit($limit + 1)
                ->get();
            $entries = $entries->merge($this->describeChanges($changes));
        }

        $sorted = $entries->sortByDesc(fn ($entry) => $entry['at']->getTimestamp())->values();

        return ['entries' => $sorted->take($limit)->all(), 'more' => $sorted->count() > $limit];
    }

    // ------------------------------------------------------------------ account events

    private function describeAccount(UserLog $log, User $member): array
    {
        $meta = $log->meta ?? [];
        $self = (int) $log->user_id === (int) $member->id;
        $other = $log->user?->name ?? 'کاربر حذف‌شده';
        $by = $log->actor && (int) $log->actor_id !== (int) $member->id ? " (توسط {$log->actor->name})" : '';

        [$tone, $text] = match ($log->action) {
            'login' => ['bg-stage-final', 'وارد سامانه شد'],
            'login.failed' => ['bg-red-500', 'ورود ناموفق'],
            'logout' => ['bg-zinc-400', 'از سامانه خارج شد'],
            'account.invited' => ['bg-stage-hr', $self
                ? 'به سامانه دعوت شد'.$by.$this->roleNote($meta)
                : "{$other} را به سامانه دعوت کرد".$this->roleNote($meta)],
            'account.link' => ['bg-stage-hr', $self ? 'برایش لینک دعوت ساخته شد'.$by : "برای {$other} لینک دعوت ساخت"],
            'account.updated' => ['bg-stage-hr', $self ? 'اطلاعات حسابش تغییر کرد'.$by : "اطلاعات حساب {$other} را تغییر داد"],
            'account.activated' => ['bg-stage-final', $self ? 'دسترسی‌اش فعال شد'.$by : "دسترسی {$other} را فعال کرد"],
            'account.deactivated' => ['bg-red-500', $self ? 'دسترسی‌اش قطع شد'.$by : "دسترسی {$other} را قطع کرد"],
            'sheet.deleted' => ['bg-red-500', 'لیست حقوق '.($meta['title'] ?? '').' را حذف کرد'],
            default => ['bg-zinc-300', $log->action],
        };

        $detail = $log->action === 'login.failed' ? ($meta['reason'] ?? null) : null;
        if ($log->action === 'account.updated' && ! empty($meta['changes'])) {
            $detail = collect($meta['changes'])->map(fn ($change, $label) => "{$label}: ".$this->pair($change[0] ?? null, $change[1] ?? null))->join('؛ ');
        }

        return [
            'at' => $log->created_at,
            'kind' => 'account',
            'tone' => $tone,
            'text' => $text,
            'context' => $log->ip ? 'IP '.$log->ip : null,
            'detail' => $detail,
            'title' => $log->user_agent,
        ];
    }

    private function roleNote(array $meta): string
    {
        $parts = array_filter([$meta['role'] ?? null, $meta['scope'] ?? null]);

        return $parts === [] ? '' : ' — '.implode('، ', $parts);
    }

    // ------------------------------------------------------------------ work inside payroll lists

    /**
     * Readable lines for list changes (also used for the approval timeline of a list). Keys are kept.
     *
     * @param  Collection<int, ChangeLog>  $changes
     */
    public function describeChanges(Collection $changes): Collection
    {
        $rows = SheetRow::whereIn('id', $changes->pluck('row_id')->filter()->unique())->get(['id', 'first_name', 'last_name'])->keyBy('id');
        $columns = SheetColumn::whereIn('id', $changes->pluck('column_id')->filter()->unique())->get(['id', 'title', 'type'])->keyBy('id');
        $sheets = Sheet::whereIn('id', $changes->pluck('sheet_id')->unique())->get()->keyBy('id');
        $projectIds = $changes->map(fn (ChangeLog $c) => $c->meta['project_id'] ?? null)->filter()->unique();
        $projects = $projectIds->isEmpty() ? collect() : Project::whereIn('id', $projectIds)->pluck('name', 'id');

        return $changes->map(function (ChangeLog $log) use ($rows, $columns, $sheets, $projects) {
            $meta = $log->meta ?? [];
            $row = $log->row_id ? ($rows->get($log->row_id)?->fullName() ?? 'ردیف حذف‌شده') : null;
            $column = $log->column_id ? ($columns->get($log->column_id)?->title ?? 'ستون حذف‌شده') : null;
            $project = isset($meta['project_id']) ? 'پروژه '.($projects[$meta['project_id']] ?? '—') : 'پروژه';

            [$tone, $text, $detail] = match ($log->action) {
                'cell.update' => ['bg-accent', "«{$column}» {$row}", $this->pair($log->old_value, $log->new_value, (bool) $columns->get($log->column_id)?->isNumber())],
                'row.update' => ['bg-accent', '«'.(self::ROW_FIELDS[$meta['field'] ?? ''] ?? 'اطلاعات پرسنلی')."» {$row}", $this->pair($log->old_value, $log->new_value)],
                'row.create' => ['bg-accent', "ردیف «{$log->new_value}» را اضافه کرد", null],
                'row.delete' => ['bg-red-500', "ردیف «{$log->old_value}» را حذف کرد", null],
                'row.project' => ['bg-accent', "پروژه‌ی {$row}", $this->pair($log->old_value ?? 'بدون پروژه', $log->new_value ?? 'بدون پروژه')],
                'row.review' => ['bg-stage-hr', "بررسی {$row}: ".(ReviewStatus::tryFrom((string) $log->new_value)?->label() ?? (string) $log->new_value), $meta['note'] ?? null],
                'row.review.reset' => ['bg-zinc-400', "رکورد ردشده‌ی {$row} دوباره به بررسی رفت", null],
                'note.create' => ['bg-zinc-400', "برای {$row} یادداشت گذاشت", null],
                'note.delete' => ['bg-red-500', 'یادداشت '.($row ?? 'لیست').' را حذف کرد', $log->old_value],
                'comment.create' => ['bg-zinc-400', "روی لیست {$project} کامنت گذاشت", null],
                'comment.print' => ['bg-zinc-400', "چاپ یک کامنت لیست {$project} را ".($log->new_value === '1' ? 'روشن' : 'خاموش').' کرد', null],
                'comment.delete' => ['bg-red-500', "کامنت لیست {$project} را حذف کرد", $log->old_value],
                'column.create' => ['bg-accent', "ستون «{$log->new_value}» را ساخت", null],
                'column.update' => ['bg-accent', "تنظیمات ستون «{$column}» را تغییر داد", null],
                'column.delete' => ['bg-red-500', "ستون «{$log->old_value}» را حذف کرد", null],
                'column.reorder' => ['bg-accent', 'ترتیب ستون‌ها را تغییر داد', null],
                'sheet.create' => ['bg-accent', 'لیست حقوق ماه را ساخت', null],
                'sheet.projects' => ['bg-accent', 'پروژه‌های ماه را تغییر داد', null],
                'sheet.month' => ['bg-stage-draft', 'ماه لیست را تغییر داد', $this->pair($log->old_value, $log->new_value)],
                'sheet.deadline' => ['bg-stage-draft', 'مهلت تکمیل را تغییر داد', $this->pair($this->date($log->old_value), $this->date($log->new_value))],
                'sheet.import' => ['bg-accent', 'فایل اکسل وارد کرد', Digits::toPersian((int) ($meta['created'] ?? 0)).' ردیف جدید، '.Digits::toPersian((int) ($meta['updated'] ?? 0)).' به‌روزرسانی'],
                'sheet.import.values' => ['bg-accent', 'مقادیر را از فایل اکسل وارد کرد', Digits::toPersian((int) ($meta['cells'] ?? 0)).' خانه در '.Digits::toPersian((int) ($meta['rows'] ?? 0)).' ردیف'],
                'stage.submit' => ['bg-stage-project', "لیست {$project} را برای تایید فرستاد", null],
                'stage.approve' => ['bg-stage-final', (Stage::tryFrom((int) $log->new_value)?->actionLabel() ?? 'تایید')." لیست {$project} با کد پیامکی", null],
                'stage.reopen' => ['bg-stage-draft', "لیست {$project} را بازگشایی کرد", $meta['reason'] ?? null],
                'stage.return' => ['bg-stage-draft', "لیست {$project} به «".(Stage::tryFrom((int) $log->new_value)?->label() ?? '—').'» برگشت', $meta['reason'] ?? null],
                default => ['bg-zinc-300', $log->action, null],
            };

            // A change to a list that was already approved (only the manager may still make one).
            $after = self::afterApproval($log);

            return [
                'at' => $log->created_at,
                'kind' => 'lists',
                'tone' => $after ? 'bg-orange-500' : $tone,
                'text' => $text,
                'context' => collect([
                    ($sheet = $sheets->get($log->sheet_id)) ? 'لیست حقوق '.$sheet->title() : null,
                    $after ? 'بعد از «'.$after->label().'»' : null,
                ])->filter()->join(' · ') ?: null,
                'detail' => $detail,
                'title' => null,
            ];
        });
    }

    /**
     * The latest approval a change came after, when it was made to an approved (locked) list: the meta written by
     * SheetEditor::afterApproval(). With $projectId, only when that project's list was among them.
     */
    public static function afterApproval(ChangeLog $log, ?int $projectId = null): ?Stage
    {
        $locked = $log->meta['after_approval'] ?? null;
        if (! is_array($locked) || $locked === []) {
            return null;
        }
        $stage = $projectId === null ? max($locked) : ($locked[$projectId] ?? null);

        return $stage === null ? null : Stage::tryFrom((int) $stage);
    }

    /** "old ← new" (reads right to left), blanks spelled out; amounts grouped when $number. */
    private function pair(?string $old, ?string $new, bool $number = false): string
    {
        return $this->value($old, $number).' ← '.$this->value($new, $number);
    }

    private function value(?string $value, bool $number): string
    {
        if ($value === null || trim($value) === '') {
            return 'خالی';
        }
        $normalized = $number ? Digits::normalizeNumber($value) : false;

        return is_string($normalized) ? Digits::money($normalized) : $value;
    }

    private function date(?string $value): ?string
    {
        return $value ? Jalali::formatLong(Carbon::parse($value)) : null;
    }
}
