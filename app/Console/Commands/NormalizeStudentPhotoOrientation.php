<?php

namespace App\Console\Commands;

use App\Models\Student;
use App\Services\StudentPhotoPathService;
use App\Services\StudentPhotoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class NormalizeStudentPhotoOrientation extends Command
{
    protected $signature = 'photos:normalize-orientation
                            {--dry-run : Report which originals would be rewritten}
                            {--limit= : Max students to process}
                            {--student= : Only process this student id}';

    protected $description = 'Rewrite student originals that have EXIF orientation and regenerate thumb/profile';

    public function __construct(
        private readonly StudentPhotoPathService $photoPaths,
        private readonly StudentPhotoService $photos
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $onlyId = $this->option('student') !== null ? (int) $this->option('student') : null;

        $this->info($dryRun
            ? 'Dry-run: no files will be changed.'
            : 'Normalizing originals with EXIF orientation…');

        $query = Student::query()->with('profile:id,profile_picture')->orderBy('id');
        if ($onlyId) {
            $query->where('id', $onlyId);
        }
        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $stats = [
            'scanned' => 0,
            'missing' => 0,
            'upright' => 0,
            'rewritten' => 0,
            'errors' => 0,
        ];

        $students = $query->get();
        $bar = $this->output->createProgressBar($students->count());
        $bar->start();

        foreach ($students as $student) {
            $stats['scanned']++;
            $bar->advance();

            $relative = $this->photoPaths->resolveRelativePath($student, 'original');
            if (! $relative || ! Storage::disk('private')->exists($relative)) {
                $stats['missing']++;

                continue;
            }

            try {
                $absolute = Storage::disk('private')->path($relative);
                $orientation = $this->photos->readExifOrientation($absolute);
                if ($orientation <= 1) {
                    $stats['upright']++;

                    continue;
                }

                if ($dryRun) {
                    $this->newLine();
                    $this->line("Would rewrite student {$student->id} (EXIF {$orientation}) {$relative}");
                    $stats['rewritten']++;

                    continue;
                }

                $this->photos->normalizeOriginalIfNeeded($student->id, $relative);
                $this->photos->writeVariantsForOriginal($relative);
                $stats['rewritten']++;
            } catch (Throwable $e) {
                $stats['errors']++;
                Log::error('[toma-foto] No se pudo convertir la orientación de la foto', [
                    'student_id' => $student->id,
                    'path' => $relative,
                    'error' => $e->getMessage(),
                ]);
                $this->newLine();
                $this->error("Student {$student->id}: {$e->getMessage()}");
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Scanned', $stats['scanned']],
                ['No original', $stats['missing']],
                ['Already upright', $stats['upright']],
                [($dryRun ? 'Would rewrite' : 'Rewritten'), $stats['rewritten']],
                ['Errors', $stats['errors']],
            ]
        );

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
