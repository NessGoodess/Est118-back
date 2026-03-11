<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Illuminate\Support\Facades\Storage;

class GenerateStudentThumbnails extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'students:generate-images';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate thumbnails and profile images for existing student photos';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        
        $manager = new ImageManager(new Driver());
        
        $files = Storage::disk('private')->allFiles('photos/students');

        $this->info('Generating thumbnails and profile images for existing student photos...');
        $bar = $this->output->createProgressBar(count($files));
        $bar->start();

        foreach ($files as $file) {

            if (
                str_contains($file, 'thumb_') ||
                str_contains($file, 'profile_')
            ) {
                continue;
            }

            try {

                $path = Storage::disk('private')->path($file);

                $image = $manager->read($path);

                $directory = dirname($file);
                $filename = basename($file);

                // Thumbnail 40x40
                $thumb = $image->cover(40, 40)
                    ->toJpeg(75);

                Storage::disk('private')->put(
                    "{$directory}/thumb_{$filename}",
                    (string) $thumb
                );

                // Reload original image
                $image = $manager->read($path);

                // Profile 400px
                $profile = $image->scale(width: 400)
                    ->toJpeg(80);

                Storage::disk('private')->put(
                    "{$directory}/profile_{$filename}",
                    (string) $profile
                );

                $this->info("Processed: {$file}");

            } catch (\Throwable $e) {

                $this->error("Error processing {$file}");
                $this->error($e->getMessage());

            }

            $bar->advance();

        }

        $bar->finish();

        $this->info(' All images processed.');
    }
}
