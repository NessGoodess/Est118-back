<?php

namespace App\Console\Commands;

use App\Models\Student;
use App\Services\StudentPhotoPathService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * Migrate legacy filename-based photos into photos/students/{id}/current/.
 *
 * Locates files by basename under photos/students/ (does not require grade/group).
 */
class MigrateStudentPhotosToStableLayout extends Command
{
    protected $signature = 'photos:migrate-to-student-id
                            {--dry-run : Report actions without writing files or DB}
                            {--limit= : Max students to process}
                            {--student= : Only migrate this student id}';

    protected $description = 'Migrate student photos from legacy filenames to stable student_id/current layout';

    /** @var array<string, list<string>> basename => relative paths */
    private array $fileIndex = [];

    public function __construct(
        private readonly StudentPhotoPathService $photoPaths
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $onlyId = $this->option('student') !== null ? (int) $this->option('student') : null;

        $this->info($dryRun ? 'Dry-run: no files or DB will be changed.' : 'Migrating photos to stable layout…');
        $this->buildFileIndex();

        $query = Student::query()
            ->with('profile:id,profile_picture')
            ->whereHas('profile', fn ($q) => $q->whereNotNull('profile_picture')->where('profile_picture', '!=', ''))
            ->orderBy('id');

        if ($onlyId) {
            $query->where('id', $onlyId);
        }

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $stats = [
            'scanned' => 0,
            'skipped_stable' => 0,
            'migrated' => 0,
            'missing' => 0,
            'conflict' => 0,
            'errors' => 0,
        ];

        $missingRows = [];
        $conflictRows = [];

        $students = $query->get();
        $bar = $this->output->createProgressBar($students->count());
        $bar->start();

        foreach ($students as $student) {
            $stats['scanned']++;
            $bar->advance();

            try {
                $result = $this->migrateOne($student, $dryRun);
                $stats[$result['status']]++;

                if ($result['status'] === 'missing') {
                    $missingRows[] = [$student->id, $result['filename'], $result['detail']];
                }
                if ($result['status'] === 'conflict') {
                    $conflictRows[] = [$student->id, $result['filename'], $result['detail']];
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
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
                ['Already stable (skipped)', $stats['skipped_stable']],
                ['Migrated'.($dryRun ? ' (would)' : ''), $stats['migrated']],
                ['Original not found', $stats['missing']],
                ['Filename conflicts', $stats['conflict']],
                ['Errors', $stats['errors']],
            ]
        );

        if ($missingRows !== []) {
            $this->warn('Missing originals:');
            $this->table(['Student', 'Filename', 'Detail'], array_slice($missingRows, 0, 30));
            if (count($missingRows) > 30) {
                $this->line('… and '.(count($missingRows) - 30).' more');
            }
        }

        if ($conflictRows !== []) {
            $this->warn('Conflicts (multiple matches):');
            $this->table(['Student', 'Filename', 'Detail'], array_slice($conflictRows, 0, 20));
        }

        $this->info('Done.');

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{status: string, filename: string, detail: string}
     */
    private function migrateOne(Student $student, bool $dryRun): array
    {
        $stored = trim((string) $student->profile?->profile_picture);
        $currentDir = $this->photoPaths->stableCurrentDirectory($student->id);
        $disk = Storage::disk('private');

        if ($stored === '') {
            return ['status' => 'missing', 'filename' => '', 'detail' => 'empty profile_picture'];
        }

        // Already on new layout (BD or files on disk).
        if (
            $this->photoPaths->isStableProfilePicture($stored)
            || $disk->exists("{$currentDir}/profile.jpg")
            || $disk->exists("{$currentDir}/original.jpg")
            || $disk->exists("{$currentDir}/original.jpeg")
            || $disk->exists("{$currentDir}/original.png")
            || $disk->exists("{$currentDir}/original.webp")
        ) {
            if (! $dryRun && ! $this->photoPaths->isStableProfilePicture($stored) && $disk->exists("{$currentDir}/profile.jpg")) {
                $student->profile?->update([
                    'profile_picture' => $this->photoPaths->stableProfilePictureValue($student->id),
                ]);
            }

            return ['status' => 'skipped_stable', 'filename' => $stored, 'detail' => 'already stable'];
        }

        $filename = basename($stored);
        $matches = $this->lookupByBasename($filename);

        // Ignore matches already inside this student's current/versions
        $matches = array_values(array_filter(
            $matches,
            fn (string $path) => ! str_contains($path, "photos/students/{$student->id}/")
        ));

        if ($matches === []) {
            return ['status' => 'missing', 'filename' => $filename, 'detail' => 'no file with that basename'];
        }

        $chosen = $this->pickBestMatch($student->id, $filename, $matches);
        if ($chosen === null) {
            return [
                'status' => 'conflict',
                'filename' => $filename,
                'detail' => implode(' | ', $matches),
            ];
        }

        $dir = dirname($chosen);
        $profileSrc = $this->firstExisting([
            ...$this->lookupByBasename("profile_{$filename}"),
            ...$this->lookupByBasename('profile_'.pathinfo($filename, PATHINFO_FILENAME).'.'.pathinfo($filename, PATHINFO_EXTENSION)),
        ]);
        // Prefer siblings next to the chosen original
        $siblingProfile = $dir.'/profile_'.basename($chosen);
        if (Storage::disk('private')->exists($siblingProfile)) {
            $profileSrc = $siblingProfile;
        }
        $thumbSrc = $this->firstExisting($this->lookupByBasename('thumb_'.basename($chosen)));
        $siblingThumb = $dir.'/thumb_'.basename($chosen);
        if (Storage::disk('private')->exists($siblingThumb)) {
            $thumbSrc = $siblingThumb;
        }

        if ($dryRun) {
            return [
                'status' => 'migrated',
                'filename' => $filename,
                'detail' => "from {$chosen}",
            ];
        }

        $disk->makeDirectory($currentDir);

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION) ?: 'jpg');
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $ext = 'jpg';
        }
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }

        $originalDest = "{$currentDir}/original.{$ext}";
        $disk->copy($chosen, $originalDest);

        if ($profileSrc) {
            $disk->copy($profileSrc, "{$currentDir}/profile.jpg");
        } else {
            $this->generateVariant($originalDest, "{$currentDir}/profile.jpg", 'profile');
        }

        if ($thumbSrc) {
            $disk->copy($thumbSrc, "{$currentDir}/thumb.jpg");
        } else {
            $this->generateVariant($originalDest, "{$currentDir}/thumb.jpg", 'thumb');
        }

        // Ensure profile exists even if copy pointed to a missing path edge-case.
        if (! $disk->exists("{$currentDir}/profile.jpg")) {
            $this->generateVariant($originalDest, "{$currentDir}/profile.jpg", 'profile');
        }
        if (! $disk->exists("{$currentDir}/thumb.jpg")) {
            $this->generateVariant($originalDest, "{$currentDir}/thumb.jpg", 'thumb');
        }

        $student->profile?->update([
            'profile_picture' => $this->photoPaths->stableProfilePictureValue($student->id),
        ]);

        return [
            'status' => 'migrated',
            'filename' => $filename,
            'detail' => "from {$chosen}",
        ];
    }

    private function buildFileIndex(): void
    {
        $this->info('Indexing files under photos/students…');
        $this->fileIndex = [];

        foreach (Storage::disk('private')->allFiles('photos/students') as $path) {
            // Do not use stable current/versions as migration sources.
            if (str_contains($path, '/current/') || str_contains($path, '/versions/')) {
                continue;
            }

            $base = strtolower(basename($path));
            $this->fileIndex[$base][] = $path;
        }

        $this->line('Indexed '.array_sum(array_map('count', $this->fileIndex)).' legacy files.');
    }

    /**
     * Case-insensitive basename lookup (Windows-safe).
     *
     * @return list<string>
     */
    private function lookupByBasename(string $basename): array
    {
        return $this->fileIndex[strtolower(basename($basename))] ?? [];
    }

    /**
     * @param  list<string>  $matches
     */
    private function pickBestMatch(int $studentId, string $filename, array $matches): ?string
    {
        if (count($matches) === 1) {
            return $matches[0];
        }

        $needle = "student_{$studentId}_";
        $preferred = array_values(array_filter(
            $matches,
            fn (string $path) => str_contains(basename($path), $needle) || str_contains($path, "/{$studentId}/")
        ));

        if (count($preferred) === 1) {
            return $preferred[0];
        }

        // Filename itself embeds student id → any single preferred, else first preferred
        if (str_contains($filename, $needle) && $preferred !== []) {
            return $preferred[0];
        }

        return null;
    }

    /**
     * @param  list<string>  $candidates
     */
    private function firstExisting(array $candidates): ?string
    {
        $disk = Storage::disk('private');
        foreach ($candidates as $path) {
            if ($path && $disk->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function generateVariant(string $sourceRelative, string $destRelative, string $kind): void
    {
        $disk = Storage::disk('private');
        $manager = new ImageManager(new Driver());
        $image = $manager->read($disk->path($sourceRelative));

        if ($kind === 'thumb') {
            $encoded = $image->cover(40, 40)->toJpeg(75);
        } else {
            $encoded = $image->scale(width: 400)->toJpeg(80);
        }

        $disk->put($destRelative, (string) $encoded);
    }
}
