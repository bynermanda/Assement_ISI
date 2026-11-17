<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Assessment attachments: delete them 6 months after approval (servers with cron; Render free uses PruneAttachmentsDaily)
Schedule::command('lampiran:prune')->daily();
