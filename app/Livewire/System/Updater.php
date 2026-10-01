<?php

namespace App\Livewire\System;

use App\Services\SheetAccess;
use App\Services\Update\UpdateManager;
use Livewire\Component;

/**
 * Update page. It only renders; every action goes to UpdateController through fetch(),
 * because Livewire requests are blocked while the site is in maintenance mode.
 */
class Updater extends Component
{
    public function mount(): void
    {
        abort_unless(app(SheetAccess::class)->canManage(auth()->user()), 403);
    }

    public function render()
    {
        $updates = app(UpdateManager::class);

        return view('livewire.system.updater', [
            'config' => [
                'summary' => $updates->summary(),
                'urls' => [
                    'status' => route('system.update.status'),
                    'check' => route('system.update.check'),
                    'run' => url('system/update/run'),
                ],
            ],
            'repository' => config('tuka.update.repository'),
            'hasToken' => filled(config('tuka.update.token')),
        ])->title('به‌روزرسانی سامانه');
    }
}
