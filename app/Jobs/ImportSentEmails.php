<?php

namespace App\Jobs;

use App\Services\MMS\SentMailImporter;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * An "Import sent emails" run. Dispatched after the response, so it runs
 * in the same request once the page has answered — no queue worker or cron
 * involved (the hosting's worker stops jobs after 45 seconds) — while the
 * progress window follows it.
 */
class ImportSentEmails
{
    use Dispatchable;

    public function __construct(
        public string $runId,
        public string $name,
        public string $senderKey,
        public string $subject,
        public string $from,
        public string $to,
        public int $userId,
    ) {}

    public function handle(SentMailImporter $importer): void
    {
        $importer->run($this->runId, $this->name, $this->senderKey, $this->subject, Carbon::parse($this->from), Carbon::parse($this->to), $this->userId);
    }
}
