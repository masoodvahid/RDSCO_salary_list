<?php

namespace App\Services;

use App\Enums\Stage;
use App\Models\Approval;
use App\Models\ChangeLog;
use App\Models\ListComment;
use App\Models\Sheet;
use App\Models\SheetProject;
use App\Models\User;
use App\Support\Digits;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What happened to a project's monthly list, shown under the grid and at the bottom of the print:
 * the approval timeline (from the change log and the signed approvals) and comments on the list.
 */
final class ListHistory
{
    private const TIMELINE_ACTIONS = ['sheet.create', 'sheet.month', 'stage.submit', 'stage.approve', 'stage.reopen', 'stage.return'];

    /** Events of the whole list (not of one project) that every project's timeline shows. */
    private const SHEET_ACTIONS = ['sheet.create', 'sheet.month'];

    /** Changes the manager made to an approved list are shown as one line per sitting, with a few examples. */
    private const AFTER_APPROVAL_EXAMPLES = 3;

    private const AFTER_APPROVAL_SITTING_MINUTES = 30;

    public function __construct(
        private readonly SheetAccess $access,
        private readonly ChangeLogger $log,
        private readonly UserActivity $activity,
    ) {}

    // ------------------------------------------------------------------ comments

    public function addComment(User $user, SheetProject $sheetProject, string $body, bool $inPrint = false): ListComment
    {
        if (! $this->access->canComment($user, $sheetProject)) {
            throw new AuthorizationException('امکان کامنت روی این لیست را ندارید.');
        }
        $body = trim($body);
        if ($body === '') {
            throw ValidationException::withMessages(['commentBody' => 'متن کامنت خالی است.']);
        }
        if (mb_strlen($body) > 2000) {
            throw ValidationException::withMessages(['commentBody' => 'کامنت حداکثر ۲۰۰۰ کاراکتر است.']);
        }

        return DB::transaction(function () use ($user, $sheetProject, $body, $inPrint) {
            $comment = ListComment::create(['sheet_project_id' => $sheetProject->id, 'user_id' => $user->id, 'body' => $body, 'in_print' => $inPrint]);
            $this->log->record($sheetProject->sheet_id, $user, 'comment.create', meta: ['project_id' => $sheetProject->project_id, 'comment_id' => $comment->id]);

            return $comment;
        });
    }

    public function setCommentPrint(User $user, ListComment $comment, bool $inPrint): void
    {
        $sheetProject = $comment->sheetProject;
        if (! $this->access->canSetCommentPrint($user, $comment, $sheetProject)) {
            throw new AuthorizationException('فقط نویسنده‌ی کامنت یا مدیر این را تعیین می‌کند.');
        }
        if ($comment->in_print === $inPrint) {
            return;
        }

        DB::transaction(function () use ($user, $comment, $sheetProject, $inPrint) {
            $comment->update(['in_print' => $inPrint]);
            $this->log->record($sheetProject->sheet_id, $user, 'comment.print', null, null, $inPrint ? '0' : '1', $inPrint ? '1' : '0', ['project_id' => $sheetProject->project_id, 'comment_id' => $comment->id]);
        });
    }

    public function deleteComment(User $user, ListComment $comment): void
    {
        if (! $this->access->canDeleteComment($user, $comment)) {
            throw new AuthorizationException('فقط مدیر می‌تواند کامنت را حذف کند.');
        }

        DB::transaction(function () use ($user, $comment) {
            $sheetProject = $comment->sheetProject;
            // The text and author stay in the log.
            $this->log->record($sheetProject->sheet_id, $user, 'comment.delete', null, null, $comment->body, null, [
                'project_id' => $sheetProject->project_id,
                'author_id' => $comment->user_id,
                'created_at' => $comment->created_at?->toDateTimeString(),
            ]);
            $comment->delete();
        });
    }

    /**
     * @param  Collection<int, SheetProject>  $sheetProjects
     * @return array<int, Collection<int, ListComment>> keyed by sheet_project_id, oldest first
     */
    public function comments(Collection $sheetProjects, bool $printOnly = false): array
    {
        $comments = ListComment::whereIn('sheet_project_id', $sheetProjects->pluck('id'))
            ->when($printOnly, fn ($q) => $q->where('in_print', true))
            ->with('user')
            ->orderBy('id')
            ->get()
            ->groupBy('sheet_project_id');

        return $sheetProjects->mapWithKeys(fn (SheetProject $sp) => [$sp->id => $comments->get($sp->id, collect())])->all();
    }

    // ------------------------------------------------------------------ approval timeline

    /**
     * Oldest first. "changed" marks a valid signature whose data has changed since (needs $hashes). Changes the
     * manager made after the list was approved (see SheetEditor::afterApproval) appear too, one line per sitting.
     *
     * @param  Collection<int, SheetProject>  $sheetProjects
     * @param  array<int, string>  $hashes  sheet_project_id => current data hash
     * @return array<int, list<array{at: Carbon, tone: string, text: string, by: ?string, detail: ?string, revoked: bool, changed: bool}>>
     */
    public function timelines(Sheet $sheet, Collection $sheetProjects, array $hashes = []): array
    {
        $logs = ChangeLog::where('sheet_id', $sheet->id)
            ->where(fn ($q) => $q->whereIn('action', self::TIMELINE_ACTIONS)->orWhereNotNull('meta->after_approval'))
            ->with('user')
            ->orderBy('id')
            ->get();
        $approvals = Approval::whereIn('sheet_project_id', $sheetProjects->pluck('id'))->get()->keyBy('id');
        $edits = $logs->filter(fn (ChangeLog $log) => UserActivity::afterApproval($log) !== null)->keyBy('id');
        $described = $edits->isEmpty() ? collect() : $this->activity->describeChanges($edits);

        $timelines = [];
        foreach ($sheetProjects as $sp) {
            $events = [];
            $sitting = null; // consecutive changes after approval by one person: [stage, logs]
            foreach ($logs as $log) {
                if ($edits->has($log->id)) {
                    $stage = UserActivity::afterApproval($log, (int) $sp->project_id);
                    if ($stage === null) {
                        continue; // a change to another project's list
                    }
                    $last = $sitting ? end($sitting[1]) : null;
                    if ($last && $last->user_id === $log->user_id && $sitting[0] === $stage
                        && $log->created_at->diffInMinutes($last->created_at, true) <= self::AFTER_APPROVAL_SITTING_MINUTES) {
                        $sitting[1][] = $log;
                    } else {
                        if ($sitting) {
                            $events[] = $this->sittingEntry($sitting, $described);
                        }
                        $sitting = [$stage, [$log]];
                    }

                    continue;
                }
                if (! in_array($log->action, self::SHEET_ACTIONS, true) && (int) ($log->meta['project_id'] ?? 0) !== (int) $sp->project_id) {
                    continue;
                }
                if ($sitting) {
                    $events[] = $this->sittingEntry($sitting, $described);
                    $sitting = null;
                }
                $events[] = $this->entry($log, $approvals, $hashes[$sp->id] ?? null);
            }
            if ($sitting) {
                $events[] = $this->sittingEntry($sitting, $described);
            }
            $timelines[$sp->id] = $events;
        }

        return $timelines;
    }

    /** @param  array{0: Stage, 1: list<ChangeLog>}  $sitting */
    private function sittingEntry(array $sitting, Collection $described): array
    {
        [$stage, $logs] = $sitting;
        $examples = collect($logs)->take(self::AFTER_APPROVAL_EXAMPLES)
            ->map(fn (ChangeLog $log) => $described->get($log->id))
            ->filter()
            ->map(fn (array $line) => $line['text'].($line['detail'] ? ': '.$line['detail'] : ''));
        $more = count($logs) - $examples->count();

        return [
            'at' => end($logs)->created_at,
            'tone' => 'bg-orange-500',
            'text' => 'بعد از «'.$stage->label().'» لیست را تغییر داد',
            'by' => $logs[0]->user?->nameWithTitle(),
            'detail' => $examples->join('؛ ').($more > 0 ? '؛ و '.Digits::toPersian($more).' تغییر دیگر' : ''),
            'revoked' => false,
            'changed' => false,
        ];
    }

    private function entry(ChangeLog $log, Collection $approvals, ?string $hash): array
    {
        $meta = $log->meta ?? [];
        $to = Stage::tryFrom((int) $log->new_value);
        $approval = isset($meta['approval_id']) ? $approvals->get($meta['approval_id']) : null;

        [$tone, $text, $detail] = match ($log->action) {
            'sheet.create' => ['bg-zinc-400', 'لیست حقوق ماه را ساخت', null],
            'sheet.month' => ['bg-zinc-400', 'ماه لیست را تغییر داد', "{$log->old_value} ← {$log->new_value}"],
            'stage.submit' => ['bg-stage-project', 'لیست را برای تایید فرستاد', null],
            'stage.approve' => [$to?->dotClass() ?? 'bg-stage-final', ($to?->actionLabel() ?? 'تایید').' با کد پیامکی',
                ! empty($meta['skipped']) ? 'بدون تایید مرحله‌ی قبل' : null],
            'stage.reopen' => ['bg-stage-draft', 'لیست را برای اصلاح بازگشایی کرد', $meta['reason'] ?? null],
            'stage.return' => ['bg-red-500', 'لیست به مرحله‌ی «'.($to?->label() ?? '—').'» برگشت', $meta['reason'] ?? null],
            default => ['bg-zinc-300', $log->action, null],
        };

        return [
            'at' => $log->created_at,
            'tone' => $tone,
            'text' => $text,
            'by' => $log->user?->nameWithTitle(),
            'detail' => $detail,
            'revoked' => (bool) $approval?->revoked_at,
            'changed' => $approval && ! $approval->revoked_at && $hash !== null && ! hash_equals($approval->data_hash, $hash),
        ];
    }
}
