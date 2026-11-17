<?php

namespace App\Console\Commands;

use App\Support\Attachments;
use Illuminate\Console\Command;

class PruneAttachments extends Command
{
    protected $signature = 'lampiran:prune';

    protected $description = 'Delete assessment attachments ' . Attachments::KEEP_MONTHS . ' months after approval, and files of deleted assessments';

    public function handle(): int
    {
        $this->info(Attachments::prune() . ' lampiran dihapus.');

        return self::SUCCESS;
    }
}
