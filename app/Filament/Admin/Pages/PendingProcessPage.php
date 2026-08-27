<?php

namespace App\Filament\Admin\Pages;

use Filament\Pages\Page;

abstract class PendingProcessPage extends Page
{
    protected string $view = 'filament.admin.pages.pending-process';

    protected static string $processDescription;

    public function getProcessDescription(): string
    {
        return static::$processDescription;
    }
}
