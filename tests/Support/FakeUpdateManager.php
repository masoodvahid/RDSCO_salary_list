<?php

namespace Tests\Support;

use App\Services\Update\UpdateManager;

/** Runs the real file logic but records maintenance/artisan calls instead of executing them. */
class FakeUpdateManager extends UpdateManager
{
    /** @var list<string> */
    public array $calls = [];

    protected function enterMaintenance(): void
    {
        $this->calls[] = 'down';
    }

    protected function leaveMaintenance(): void
    {
        $this->calls[] = 'up';
    }

    protected function artisan(string $command, array $parameters = []): string
    {
        $this->calls[] = $command;

        return '';
    }

    protected function resetOpcache(): void {}
}
