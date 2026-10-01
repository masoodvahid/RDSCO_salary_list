<?php

namespace Tests\Feature;

use App\Livewire\Sheets\Grid;
use App\Models\ChangeLog;
use App\Models\Note;
use App\Models\User;
use App\Services\ReviewService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsSheets;
use Tests\TestCase;

class NotesTest extends TestCase
{
    use BuildsSheets, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSheet();
    }

    public function test_viewer_adds_a_note_and_only_the_manager_deletes_it(): void
    {
        $viewer = User::factory()->viewer()->create();
        $reviews = app(ReviewService::class);
        $note = $reviews->addNote($viewer, $this->rowA, 'کارکرد این ماه را چک کنید.');

        try {
            $reviews->deleteNote($viewer, $note);
            $this->fail('A viewer must not delete notes.');
        } catch (AuthorizationException) {
            $this->assertTrue(Note::whereKey($note->id)->exists());
        }

        $reviews->deleteNote($this->manager, $note);

        $this->assertFalse(Note::whereKey($note->id)->exists());
        $log = ChangeLog::where('action', 'note.delete')->firstOrFail();
        $this->assertSame('کارکرد این ماه را چک کنید.', $log->old_value);
        $this->assertSame($viewer->id, $log->meta['author_id']);
    }

    public function test_manager_deletes_a_note_from_the_notes_dialog(): void
    {
        $note = app(ReviewService::class)->addNote($this->manager, $this->rowA, 'یادداشت آزمایشی');

        Livewire::actingAs($this->manager)
            ->test(Grid::class, ['sheet' => $this->sheet])
            ->call('openNotes', $this->rowA->id)
            ->assertSee('یادداشت آزمایشی')
            ->call('deleteNote', $note->id)
            ->assertDontSee('یادداشت آزمایشی');

        $this->assertSame(0, Note::count());
    }

    public function test_editor_cannot_delete_notes_through_the_grid(): void
    {
        $editor = User::factory()->editor($this->projectA)->create();
        $note = app(ReviewService::class)->addNote($this->manager, $this->rowA, 'فقط مدیر حذف می‌کند');

        Livewire::actingAs($editor)
            ->test(Grid::class, ['sheet' => $this->sheet])
            ->call('openNotes', $this->rowA->id)
            ->assertDontSeeHtml('wire:click="deleteNote(')
            ->call('deleteNote', $note->id)
            ->assertForbidden();

        $this->assertSame(1, Note::count());
    }
}
