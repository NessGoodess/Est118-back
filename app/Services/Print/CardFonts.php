<?php

namespace App\Services\Print;

class CardFonts
{
    public const DIR = 'app/card-templates/fonts';

    public const DEFAULT_FILE = 'arial.ttf';

    public const DEFAULT_BOLD = 'arialbd.ttf';

    /**
     * @return list<array{id: string, label: string, file: string, file_bold: string}>
     */
    public static function catalog(): array
    {
        return [
            [
                'id' => 'arial',
                'label' => 'Arial',
                'file' => 'arial.ttf',
                'file_bold' => 'arialbd.ttf',
            ],
            [
                'id' => 'montserrat-bold',
                'label' => 'Montserrat Bold',
                'file' => 'Montserrat-Bold.ttf',
                'file_bold' => 'Montserrat-Bold.ttf',
            ],
            [
                'id' => 'inter-semibold',
                'label' => 'Inter SemiBold',
                'file' => 'Inter-SemiBold.ttf',
                'file_bold' => 'Inter-SemiBold.ttf',
            ],
            [
                'id' => 'ibm-plex-mono',
                'label' => 'IBM Plex Mono Regular',
                'file' => 'IBMPlexMono-Regular.ttf',
                'file_bold' => 'IBMPlexMono-Regular.ttf',
            ],
            [
                'id' => 'great-vibes',
                'label' => 'Great Vibes',
                'file' => 'GreatVibes-Regular.ttf',
                'file_bold' => 'GreatVibes-Regular.ttf',
            ],
        ];
    }

    /**
     * @return array<string, true>
     */
    public static function allowedFiles(): array
    {
        $files = [];
        foreach (self::catalog() as $font) {
            $files[$font['file']] = true;
            $files[$font['file_bold']] = true;
        }

        return $files;
    }

    public static function normalize(string $raw, string $fallback = self::DEFAULT_FILE): string
    {
        $name = basename(str_replace('\\', '/', $raw));
        if ($name === '' || ! isset(self::allowedFiles()[$name])) {
            return $fallback;
        }

        return $name;
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function pairFromFile(string $file): array
    {
        $file = self::normalize($file);
        foreach (self::catalog() as $font) {
            if ($font['file'] === $file || $font['file_bold'] === $file) {
                return [$font['file'], $font['file_bold']];
            }
        }

        return [self::DEFAULT_FILE, self::DEFAULT_BOLD];
    }

    public static function resolvePath(string $file): string
    {
        $name = self::normalize($file);
        $path = storage_path(self::DIR.DIRECTORY_SEPARATOR.$name);
        if (is_file($path)) {
            return $path;
        }

        return storage_path(self::DIR.DIRECTORY_SEPARATOR.self::DEFAULT_FILE);
    }
}
