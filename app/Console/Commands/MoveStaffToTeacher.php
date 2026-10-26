<?php

namespace App\Console\Commands;

use App\Enums\CredentialHolderKind;
use App\Enums\PersonPhotoKind;
use App\Models\Profile;
use App\Models\Staff;
use App\Models\Teacher;
use App\Services\People\PersonPhotoPathService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class MoveStaffToTeacher extends Command
{
    protected $signature = 'staff:move-to-teacher
                            {curp : CURP de la persona que está en personal}
                            {--dry-run : Muestra el cambio sin escribir}';

    protected $description = 'Pasa una persona de personal a docentes por CURP, sin cambiar el perfil ni las fechas, y mueve su foto';

    /** @var list<array{0: string, 1: string}> */
    private array $movedPhotos = [];

    public function __construct(
        private readonly PersonPhotoPathService $photos,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $curp = strtoupper(trim((string) $this->argument('curp')));
        $profile = Profile::query()
            ->whereRaw('UPPER(national_id) = ?', [$curp])
            ->first();

        if ($profile === null) {
            $this->error("No hay un perfil con la CURP {$curp}.");

            return self::FAILURE;
        }

        $staff = $profile->staff;
        if ($staff === null) {
            $this->error("{$profile->fullName()} no está en personal.");

            return self::FAILURE;
        }

        if ($profile->teacher !== null) {
            $this->error("{$profile->fullName()} ya es docente. No se movió nada.");

            return self::FAILURE;
        }

        $photoRoot = $this->photoRoot(PersonPhotoKind::Staff, $staff->id);
        $photoCount = count(Storage::disk('private')->allFiles($photoRoot));

        if ($this->option('dry-run')) {
            $this->info("Simulación: {$profile->fullName()} ({$curp}) pasaría de personal #{$staff->id} a docentes.");
            $this->line("Foto: {$photoCount} archivo(s) en {$photoRoot}.");
            $this->line('No se escribió nada.');

            return self::SUCCESS;
        }

        $this->movedPhotos = [];

        try {
            $teacherId = DB::transaction(function () use ($profile, $staff): int {
                $staffRow = DB::table('staff')->where('id', $staff->id)->lockForUpdate()->first();
                if ($staffRow === null) {
                    throw new RuntimeException('El registro de personal ya no existe.');
                }
                if (DB::table('teachers')->where('profile_id', $profile->id)->lockForUpdate()->exists()) {
                    throw new RuntimeException("{$profile->fullName()} ya es docente.");
                }

                $teacherId = DB::table('teachers')->insertGetId([
                    'profile_id' => $staffRow->profile_id,
                    'employee' => null,
                    'classroom' => null,
                    'position' => $staffRow->position,
                    'department' => $staffRow->department,
                    'status' => $staffRow->status,
                    'created_at' => $staffRow->created_at,
                    'updated_at' => $staffRow->updated_at,
                ]);

                $this->movePhotoTree($staff->id, $teacherId);
                $this->rewritePicturePath($profile->id, $staff->id, $teacherId);
                $this->retagCredentials($profile->id);
                $this->retagMorphs($staff->id, $teacherId);

                DB::table('staff')->where('id', $staff->id)->delete();

                return $teacherId;
            });
        } catch (Throwable $e) {
            $this->restoreMovedPhotos();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->movedPhotos = [];
        $moved = count(Storage::disk('private')->allFiles($this->photoRoot(PersonPhotoKind::Teachers, $teacherId)));
        $this->info("{$profile->fullName()} ({$curp}) pasó de personal #{$staff->id} a docente #{$teacherId}.");
        $this->line("Foto: {$moved} archivo(s). El perfil y sus fechas no se reescribieron.");

        return self::SUCCESS;
    }

    private function photoRoot(PersonPhotoKind $kind, int $id): string
    {
        return dirname($this->photos->currentDirectory($kind, $id));
    }

    private function movePhotoTree(int $staffId, int $teacherId): void
    {
        $disk = Storage::disk('private');
        $from = $this->photoRoot(PersonPhotoKind::Staff, $staffId);
        $to = $this->photoRoot(PersonPhotoKind::Teachers, $teacherId);
        $files = $disk->allFiles($from);

        if ($files === []) {
            return;
        }

        if ($disk->exists($to)) {
            throw new RuntimeException("Ya hay archivos de foto en {$to}.");
        }

        $moved = [];
        try {
            foreach ($files as $path) {
                $target = $to.substr($path, strlen($from));
                $disk->move($path, $target);
                $moved[] = [$path, $target];
            }
            $disk->deleteDirectory($from);
        } catch (Throwable $e) {
            $this->movedPhotos = $moved;
            $this->restoreMovedPhotos();
            throw $e;
        }

        $this->movedPhotos = $moved;
    }

    private function restoreMovedPhotos(): void
    {
        $disk = Storage::disk('private');
        foreach (array_reverse($this->movedPhotos) as [$origin, $target]) {
            if ($disk->exists($target)) {
                $disk->move($target, $origin);
            }
        }
        $this->movedPhotos = [];
    }

    private function rewritePicturePath(int $profileId, int $staffId, int $teacherId): void
    {
        $profile = DB::table('profiles')->where('id', $profileId)->lockForUpdate()->first();
        $current = (string) ($profile->profile_picture ?? '');
        $needle = "staff/{$staffId}/";
        if ($current === '' || ! str_contains($current, $needle)) {
            return;
        }

        DB::table('profiles')->where('id', $profileId)->update([
            'profile_picture' => str_replace($needle, "teachers/{$teacherId}/", $current),
            'updated_at' => $profile->updated_at,
        ]);
    }

    private function retagCredentials(int $profileId): void
    {
        foreach (['credential_prints', 'print_jobs'] as $table) {
            DB::table($table)
                ->where('profile_id', $profileId)
                ->where('kind', CredentialHolderKind::Staff->value)
                ->update([
                    'kind' => CredentialHolderKind::Teachers->value,
                    'updated_at' => DB::raw('updated_at'),
                ]);
        }
    }

    private function retagMorphs(int $staffId, int $teacherId): void
    {
        $maps = [
            ['incidents', 'recorded_by_type', 'recorded_by_id', true],
            ['absence_requests', 'reviewed_by_type', 'reviewed_by_id', true],
            ['id_card_print_logs', 'generated_by_type', 'generated_by_id', false],
        ];

        foreach ($maps as [$table, $typeColumn, $idColumn, $hasTimestamps]) {
            $values = [
                $typeColumn => Teacher::class,
                $idColumn => $teacherId,
            ];
            if ($hasTimestamps) {
                $values['updated_at'] = DB::raw('updated_at');
            }

            DB::table($table)
                ->where($typeColumn, Staff::class)
                ->where($idColumn, $staffId)
                ->update($values);
        }
    }
}
