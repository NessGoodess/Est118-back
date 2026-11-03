<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Models\Staff;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Access\ProfileAccessLinkService;
use Illuminate\Console\Command;

class LinkUsersByEmailCommand extends Command
{
    protected $signature = 'profiles:link-users-by-email
                            {--dry-run : Solo lista candidatos (default si no hay --apply)}
                            {--apply : Ejecuta los vínculos}
                            {--kind=all : teachers|staff|all}
                            {--as= : user_id del actor para auditoría}';

    protected $description = 'Vincula profiles teacher/staff a users existentes por email exacto';

    public function handle(ProfileAccessLinkService $links): int
    {
        $apply = (bool) $this->option('apply');
        $kind = strtolower((string) $this->option('kind') ?: 'all');
        if (! in_array($kind, ['teachers', 'staff', 'all'], true)) {
            $this->error('kind debe ser teachers, staff o all.');

            return self::FAILURE;
        }

        $actorId = $this->option('as') ? (int) $this->option('as') : null;
        $actor = $actorId ? User::query()->find($actorId) : null;
        if ($apply && $actorId && ! $actor) {
            $this->error("No existe el user --as={$actorId}");

            return self::FAILURE;
        }

        $rows = [];
        if ($kind === 'teachers' || $kind === 'all') {
            $rows = array_merge($rows, $this->collectKind('teacher', Teacher::query()->with('profile')->get()));
        }
        if ($kind === 'staff' || $kind === 'all') {
            $rows = array_merge($rows, $this->collectKind('staff', Staff::query()->with('profile')->get()));
        }

        if ($rows === []) {
            $this->info('No hay emparejamientos por email.');

            return self::SUCCESS;
        }

        $this->table(
            ['kind', 'holder_id', 'profile_id', 'email', 'user_id', 'user_name'],
            array_map(static fn (array $row) => [
                $row['kind'],
                $row['holder_id'],
                $row['profile_id'],
                $row['email'],
                $row['user_id'],
                $row['user_name'],
            ], $rows)
        );

        if (! $apply) {
            $this->warn('Dry-run: '.count($rows).' candidato(s). Usa --apply para vincular.');

            return self::SUCCESS;
        }

        $linked = 0;
        foreach ($rows as $row) {
            $profile = Profile::query()->find($row['profile_id']);
            $user = User::query()->find($row['user_id']);
            if (! $profile || ! $user) {
                continue;
            }
            try {
                $links->link($profile, $user, $actor ?? $user, confirm: true);
                $linked++;
            } catch (\Throwable $exception) {
                $this->error("Falló profile {$row['profile_id']}: ".$exception->getMessage());
            }
        }

        $this->info("Vinculados: {$linked}");

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Teacher|Staff>  $holders
     * @return list<array{kind: string, holder_id: int, profile_id: int, email: string, user_id: int, user_name: string}>
     */
    private function collectKind(string $kind, $holders): array
    {
        $out = [];
        foreach ($holders as $holder) {
            $profile = $holder->profile;
            if (! $profile || $profile->user_id) {
                continue;
            }
            $email = strtolower(trim((string) $profile->email));
            if ($email === '') {
                continue;
            }
            $user = User::query()->where('email', $email)->first();
            if (! $user) {
                continue;
            }
            if (Profile::query()->where('user_id', $user->id)->exists()) {
                continue;
            }
            $out[] = [
                'kind' => $kind,
                'holder_id' => (int) $holder->id,
                'profile_id' => (int) $profile->id,
                'email' => $email,
                'user_id' => (int) $user->id,
                'user_name' => (string) $user->name,
            ];
        }

        return $out;
    }
}
