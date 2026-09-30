<?php

namespace App\Console\Commands;

use App\Models\CredentialPrint;
use App\Services\Print\CredentialPrintService;
use Illuminate\Console\Command;
use Throwable;

class DedupeOpenCredentialPrints extends Command
{
    protected $signature = 'credential-prints:dedupe-open
                            {--dry-run : Report which open cards would be discarded}';

    protected $description = 'Leave a single open credential print per student, discarding the extras';

    public function __construct(
        private readonly CredentialPrintService $cards
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun
            ? 'Dry-run: no cards will be discarded.'
            : 'Discarding duplicate open credential prints…');

        $open = CredentialPrint::query()
            ->open()
            ->with(['printJobs', 'student.profile:id,first_name,last_name'])
            ->orderBy('id')
            ->get()
            ->groupBy('student_id');

        $stats = [
            'students' => 0,
            'discarded' => 0,
            'errors' => 0,
        ];

        foreach ($open as $cards) {
            if ($cards->count() < 2) {
                continue;
            }

            $stats['students']++;
            $keep = $this->pickKeeper($cards);
            $extras = $cards->filter(fn (CredentialPrint $card) => $card->id !== $keep->id);

            foreach ($extras as $card) {
                $name = $this->studentName($card);

                if ($dryRun) {
                    $this->line("Would discard card #{$card->id} of {$name} (keeps #{$keep->id}).");
                    $stats['discarded']++;

                    continue;
                }

                try {
                    $this->cards->discard(
                        null,
                        [$card->id],
                        null,
                        'Duplicado detectado al activar protección'
                    );
                    $stats['discarded']++;
                } catch (Throwable $e) {
                    $stats['errors']++;
                    $this->error("Card #{$card->id} ({$name}): {$e->getMessage()}");
                }
            }
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Students with duplicates', $stats['students']],
                [($dryRun ? 'Would discard' : 'Discarded'), $stats['discarded']],
                ['Errors', $stats['errors']],
            ]
        );

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CredentialPrint>  $cards
     */
    private function pickKeeper($cards): CredentialPrint
    {
        return $cards->sortBy(fn (CredentialPrint $card) => [
            $card->activeJob() ? 0 : 1,
            $card->needsBack() ? 0 : 1,
            -$card->id,
        ])->first();
    }

    private function studentName(CredentialPrint $card): string
    {
        $profile = $card->student?->profile;

        $name = $profile
            ? trim(($profile->first_name ?? '').' '.($profile->last_name ?? ''))
            : '';

        return $name !== '' ? $name : "alumno #{$card->student_id}";
    }
}
