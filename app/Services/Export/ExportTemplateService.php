<?php

namespace App\Services\Export;

use App\Models\ExportTemplate;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ExportTemplateService
{
    public const DISK = 'private';

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, ExportTemplate>
     */
    public function listForUser(User $user, ?string $context = null)
    {
        $query = ExportTemplate::query()
            ->where('user_id', $user->id)
            ->orderByDesc('is_default')
            ->orderBy('name');

        if ($context) {
            $query->where('context', $context);
        }

        return $query->get();
    }

    public function store(
        User $user,
        UploadedFile $file,
        string $name,
        string $context,
        bool $isDefault = false
    ): ExportTemplate {
        $directory = "export-templates/{$user->id}";
        $filename = Str::uuid()->toString().'.xlsx';
        $path = $file->storeAs($directory, $filename, self::DISK);

        if (! $path) {
            throw new RuntimeException('No se pudo guardar la plantilla.');
        }

        return DB::transaction(function () use ($user, $name, $context, $path, $file, $isDefault) {
            if ($isDefault) {
                $this->clearDefaults($user->id, $context);
            } elseif (! ExportTemplate::query()
                ->where('user_id', $user->id)
                ->where('context', $context)
                ->exists()) {
                $isDefault = true;
            }

            return ExportTemplate::create([
                'user_id' => $user->id,
                'name' => $name,
                'context' => $context,
                'path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                'size' => $file->getSize() ?: 0,
                'is_default' => $isDefault,
            ]);
        });
    }

    public function setDefault(ExportTemplate $template): ExportTemplate
    {
        return DB::transaction(function () use ($template) {
            $this->clearDefaults($template->user_id, $template->context);

            $template->update(['is_default' => true]);

            return $template->fresh();
        });
    }

    public function delete(ExportTemplate $template): void
    {
        $path = $template->path;
        $template->delete();

        if ($path && Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    private function clearDefaults(int $userId, string $context): void
    {
        ExportTemplate::query()
            ->where('user_id', $userId)
            ->where('context', $context)
            ->update(['is_default' => false]);
    }
}
