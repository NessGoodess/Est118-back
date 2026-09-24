<?php

namespace App\Services\Print;

use App\Enums\PrintJobStatus;
use App\Enums\WorkshopEnrollmentStatus;
use App\Models\PrintJob;
use App\Models\Student;
use App\Services\CredentialPrintingService;
use App\Services\StudentPhotoPathService;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class StudentCardRenderService
{
    public function __construct(
        private readonly StudentPhotoPathService $photoPaths,
        private readonly CredentialPrintingService $credentials,
        private readonly CardTemplateService $templates
    ) {}

    public function render(PrintJob $job): PrintJob
    {
        $payload = $job->payload_json ?? [];
        $payload = is_array($payload) ? $payload : [];
        $templateKey = (string) $job->template_key;
        $side = $job->side_mode === 'back' ? 'back' : 'front';

        $frontRel = 'print-jobs/'.$job->uuid.'/front.png';
        $this->writePng(
            $this->compose($templateKey, (int) $job->student_id, $payload, $side),
            $frontRel
        );

        $update = [
            'front_path' => $frontRel,
            'back_path' => null,
            'side_mode' => $side,
            'status' => PrintJobStatus::Ready,
            'last_error' => null,
        ];

        $job->update($update);

        return $job->fresh();
    }

    /**
     * Build a preview PNG binary for a student + template (does not create a print job).
     *
     * @param  array<string, mixed>|null  $payloadOverride
     */
    public function previewPng(int $studentId, string $templateKey = 'student-card-v1', ?array $payloadOverride = null): string
    {
        $student = Student::query()
            ->with([
                'profile.address',
                'guardians.profile',
                'currentEnrollment.classGroup.gradeLevel',
                'workshopEnrollments.workshop',
            ])
            ->findOrFail($studentId);

        $payload = $payloadOverride ?? $this->payloadFromStudent($student);
        $img = $this->compose($templateKey, $studentId, $payload, 'front');

        ob_start();
        imagepng($img);
        $binary = (string) ob_get_clean();
        imagedestroy($img);

        return $binary;
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadFromStudent(Student $student): array
    {
        $enrollment = $student->currentEnrollment;
        $grade = $enrollment?->classGroup?->gradeLevel?->name ?? '';
        $group = $enrollment?->classGroup?->name ?? '';
        $fullName = trim(($student->profile?->first_name ?? '').' '.($student->profile?->last_name ?? ''));

        $yearId = (int) ($enrollment?->academic_year_id ?? 0);
        $workshopName = '';
        if ($yearId > 0) {
            $workshopRow = $student->workshopEnrollmentForYear($yearId);
            if ($workshopRow && $workshopRow->status === WorkshopEnrollmentStatus::Assigned) {
                $workshopName = (string) ($workshopRow->workshop?->name ?? '');
            }
        }

        $guardian = $student->relationLoaded('guardians')
            ? $student->guardians->first()
            : $student->guardians()->with('profile')->first();
        $gProfile = $guardian?->profile;
        $tutorName = $gProfile
            ? trim(($gProfile->first_name ?? '').' '.($gProfile->last_name ?? ''))
            : '';

        return [
            'full_name' => $fullName,
            'grade' => $grade,
            'group' => $group,
            'grade_group' => trim($grade.' '.$group),
            'curp' => $student->profile?->national_id ?? '',
            'credential_id' => $student->credential_id,
            'address' => $this->credentials->formatAddress($student->profile?->address),
            'workshop' => $workshopName,
            'tutor' => $tutorName,
            'student_phone' => (string) ($student->profile?->phone_number ?? ''),
            'tutor_phone' => (string) ($gProfile?->phone_number ?? ''),
            'school' => 'EST 118',
            'role' => '',
            'department' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return \GdImage|resource
     */
    public function compose(string $templateKey, int $studentId, array $payload, string $side = 'front')
    {
        if (! function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('PHP GD extension is required to render student cards.');
        }

        $templateDir = $this->templates->directory($templateKey);
        $layout = $this->templates->layoutFor($templateKey);

        $width = (int) ($layout['width'] ?? 1016);
        $height = (int) ($layout['height'] ?? 648);
        $img = imagecreatetruecolor($width, $height);
        if ($img === false) {
            throw new RuntimeException('Could not create card canvas.');
        }

        imagealphablending($img, true);
        imagesavealpha($img, true);

        $face = $this->resolveFace($layout, $side === 'back' ? 'back' : 'front');

        $bg = $this->hexToColor($img, (string) ($face['background'] ?? '#0B3D5C'));
        imagefilledrectangle($img, 0, 0, $width, $height, $bg);

        $bgName = $face['background_image'] ?? null;
        $bgFile = is_string($bgName) && $bgName !== '' ? $templateDir.'/'.$bgName : '';
        $hasBgImage = $bgFile !== '' && is_file($bgFile);
        if ($hasBgImage) {
            $this->drawBackgroundImage($img, $bgFile, $width, $height);
        } elseif (! empty($face['accent'])) {
            $accent = $this->hexToColor($img, (string) $face['accent']);
            imagefilledrectangle($img, 0, 0, $width, 56, $accent);
        }

        $layers = is_array($face['layers'] ?? null) ? $face['layers'] : [];
        if ($layers === []) {
            foreach (($face['graphics'] ?? []) as $graphic) {
                if (is_array($graphic)) {
                    $layers[] = ['kind' => 'graphic', 'id' => $graphic['id'] ?? ''];
                }
            }
            if (is_array($face['photo'] ?? null)) {
                $layers[] = ['kind' => 'photo'];
            }
            if (is_array($face['qr'] ?? null)) {
                $layers[] = ['kind' => 'qr'];
            }
            foreach (($face['fields'] ?? []) as $field) {
                if (is_array($field) && ! empty($field['key'])) {
                    $layers[] = ['kind' => 'field', 'key' => $field['key']];
                }
            }
        }

        foreach ($layers as $layer) {
            if (! is_array($layer)) {
                continue;
            }
            $kind = (string) ($layer['kind'] ?? '');
            if ($kind === 'graphic') {
                $id = (string) ($layer['id'] ?? '');
                foreach (($face['graphics'] ?? []) as $graphic) {
                    if (is_array($graphic) && (string) ($graphic['id'] ?? '') === $id) {
                        $this->drawGraphic($img, $templateDir, $graphic);
                        break;
                    }
                }
                continue;
            }
            if ($kind === 'photo') {
                $photoBox = $face['photo'] ?? null;
                if (is_array($photoBox)) {
                    $this->drawPhoto(
                        $img,
                        $studentId,
                        (int) ($photoBox['x'] ?? 60),
                        (int) ($photoBox['y'] ?? 120),
                        (int) ($photoBox['w'] ?? 280),
                        (int) ($photoBox['h'] ?? 360),
                        (bool) ($photoBox['border'] ?? true),
                        max(0, (int) ($photoBox['radius'] ?? 0)),
                        (string) ($photoBox['border_color'] ?? '#FFFFFF'),
                        max(1, min(32, (int) ($photoBox['border_width'] ?? 4)))
                    );
                }
                continue;
            }
            if ($kind === 'qr') {
                $qrBox = $face['qr'] ?? null;
                if (is_array($qrBox)) {
                    $this->drawQr(
                        $img,
                        (string) ($payload['curp'] ?? ''),
                        (int) ($qrBox['x'] ?? 0),
                        (int) ($qrBox['y'] ?? 0),
                        max(32, (int) ($qrBox['size'] ?? 120)),
                        (string) ($qrBox['color'] ?? '#000000'),
                        (string) ($qrBox['background'] ?? '#FFFFFF')
                    );
                }
                continue;
            }
            if ($kind === 'field') {
                $key = (string) ($layer['key'] ?? '');
                foreach (($face['fields'] ?? []) as $field) {
                    if (is_array($field) && (string) ($field['key'] ?? '') === $key) {
                        $this->drawLayoutField($img, $field, $payload, $layout, (string) ($face['text'] ?? '#FFFFFF'));
                        break;
                    }
                }
            }
        }

        return $img;
    }

    /**
     * @param  \GdImage|resource  $img
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $layout
     */
    private function drawLayoutField($img, array $field, array $payload, array $layout, string $defaultColor): void
    {
        $key = (string) ($field['key'] ?? '');
        $label = (string) ($field['label'] ?? '');
        $isStatic = $key === 'static' || str_starts_with($key, 'static_');
        if ($isStatic) {
            $text = $label;
        } else {
            $value = (string) ($payload[$key] ?? '');
            if ($value === '') {
                return;
            }
            $text = $label !== '' ? $label.$value : $value;
        }
        if ($text === '') {
            return;
        }
        $bold = (bool) ($field['bold'] ?? false);
        [$regular, $boldFile] = CardFonts::pairFromFile(
            (string) ($field['font'] ?? $layout['font'] ?? CardFonts::DEFAULT_FILE)
        );
        $align = in_array(($field['align'] ?? 'left'), ['left', 'center', 'right'], true)
            ? (string) $field['align']
            : 'left';

        $this->drawText(
            $img,
            $text,
            (int) ($field['x'] ?? 0),
            (int) ($field['y'] ?? 0),
            (float) ($field['size'] ?? 22),
            $this->hexToColor($img, (string) ($field['color'] ?? $defaultColor)),
            CardFonts::resolvePath($bold ? $boldFile : $regular),
            max(48, (int) ($field['w'] ?? 0)),
            $align
        );
    }

    /**
     * @param  \GdImage|resource  $img
     */
    private function writePng($img, string $relative): void
    {
        $absolute = Storage::disk('private')->path($relative);
        $dir = dirname($absolute);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (! imagepng($img, $absolute)) {
            imagedestroy($img);
            throw new RuntimeException('Failed to write PNG: '.$relative);
        }
        imagedestroy($img);
    }

    /**
     * @param  array<string, mixed>  $layout
     * @return array<string, mixed>
     */
    private function resolveFace(array $layout, string $side): array
    {
        if (isset($layout['faces'][$side]) && is_array($layout['faces'][$side])) {
            return $layout['faces'][$side];
        }

        if ($side !== 'front') {
            return [
                'background' => '#E8E8E8',
                'background_image' => null,
                'accent' => null,
                'text' => '#111111',
                'photo' => null,
                'qr' => null,
                'fields' => [],
            ];
        }

        return [
            'background' => $layout['background'] ?? '#FFFFFF',
            'background_image' => $layout['background_image'] ?? 'front-bg.png',
            'accent' => $layout['accent'] ?? '',
            'text' => $layout['text'] ?? '#111111',
            'photo' => $layout['photo'] ?? null,
            'qr' => $layout['qr'] ?? null,
            'fields' => $layout['fields'] ?? [],
        ];
    }

    /**
     * @param  \GdImage|resource  $img
     */
    private function drawBackgroundImage($img, string $path, int $width, int $height): void
    {
        $bytes = (string) file_get_contents($path);
        $bg = @imagecreatefromstring($bytes);
        if ($bg === false) {
            return;
        }
        $this->copyCover($img, $bg, 0, 0, $width, $height);
        imagedestroy($bg);
    }

    /**
     * @param  \GdImage|resource  $img
     * @param  array<string, mixed>  $graphic
     */
    private function drawGraphic($img, string $templateDir, array $graphic): void
    {
        $x = (int) ($graphic['x'] ?? 0);
        $y = (int) ($graphic['y'] ?? 0);
        $w = max(8, (int) ($graphic['w'] ?? 80));
        $h = max(8, (int) ($graphic['h'] ?? 80));
        $kind = (string) ($graphic['kind'] ?? 'rect');

        if ($kind === 'image') {
            $name = (string) ($graphic['src'] ?? '');
            if ($name === '' || ! preg_match('/^graphic-[A-Za-z0-9._-]+\.(png|jpe?g|webp)$/i', basename($name))) {
                return;
            }
            $path = $templateDir.'/'.basename($name);
            if (! is_file($path)) {
                return;
            }
            $src = @imagecreatefromstring((string) file_get_contents($path));
            if ($src === false) {
                return;
            }
            $this->copyContain($img, $src, $x, $y, $w, $h);
            imagedestroy($src);

            return;
        }

        $fill = $this->hexToColor($img, (string) ($graphic['fill'] ?? '#2563EB'));
        $strokeW = max(0, min(32, (int) ($graphic['stroke_width'] ?? 0)));
        $strokeHex = (string) ($graphic['stroke'] ?? '');
        if ($kind === 'circle') {
            if ($strokeW > 0 && $strokeHex !== '') {
                imagefilledellipse(
                    $img,
                    (int) ($x + $w / 2),
                    (int) ($y + $h / 2),
                    $w + $strokeW * 2,
                    $h + $strokeW * 2,
                    $this->hexToColor($img, $strokeHex)
                );
            }
            imagefilledellipse($img, (int) ($x + $w / 2), (int) ($y + $h / 2), $w, $h, $fill);

            return;
        }

        $radius = max(0, (int) ($graphic['radius'] ?? 0));
        if ($strokeW > 0 && $strokeHex !== '') {
            $this->fillRoundedRect(
                $img,
                $x - $strokeW,
                $y - $strokeW,
                $w + $strokeW * 2,
                $h + $strokeW * 2,
                $radius + $strokeW,
                $this->hexToColor($img, $strokeHex)
            );
        }
        $this->fillRoundedRect($img, $x, $y, $w, $h, $radius, $fill);
    }

    /**
     * @param  \GdImage|resource  $img
     */
    private function drawPhoto(
        $img,
        int $studentId,
        int $x,
        int $y,
        int $w,
        int $h,
        bool $border = true,
        int $radius = 0,
        string $borderColor = '#FFFFFF',
        int $borderWidth = 4
    ): void {
        $radius = min($radius, (int) floor(min($w, $h) / 2));

        if ($border) {
            $pad = max(1, min(32, $borderWidth));
            $this->fillRoundedRect(
                $img,
                $x - $pad,
                $y - $pad,
                $w + ($pad * 2),
                $h + ($pad * 2),
                $radius + $pad,
                $this->hexToColor($img, $borderColor !== '' ? $borderColor : '#FFFFFF')
            );
        }

        $student = Student::query()->find($studentId);
        $rel = $student
            ? ($this->photoPaths->resolveRelativePath($student, 'original')
                ?? $this->photoPaths->resolveRelativePath($student, 'profile'))
            : null;

        $layer = imagecreatetruecolor($w, $h);
        if ($layer === false) {
            return;
        }
        imagealphablending($layer, false);
        imagesavealpha($layer, true);
        $clear = imagecolorallocatealpha($layer, 0, 0, 0, 127);
        imagefilledrectangle($layer, 0, 0, $w, $h, $clear);
        imagealphablending($layer, true);

        if (! $rel || ! Storage::disk('private')->exists($rel)) {
            $placeholder = $this->hexToColor($layer, '#334155');
            imagefilledrectangle($layer, 0, 0, $w, $h, $placeholder);
            $this->drawText(
                $layer,
                'SIN FOTO',
                (int) ($w * 0.25),
                (int) ($h * 0.5),
                18,
                $this->hexToColor($layer, '#FFFFFF'),
                CardFonts::resolvePath(CardFonts::DEFAULT_BOLD)
            );
        } else {
            $bytes = Storage::disk('private')->get($rel);
            $photo = @imagecreatefromstring($bytes);
            if ($photo !== false) {
                $this->copyCover($layer, $photo, 0, 0, $w, $h);
                imagedestroy($photo);
            }
        }

        $this->applyRoundedMask($layer, $radius);
        imagecopy($img, $layer, $x, $y, 0, 0, $w, $h);
        imagedestroy($layer);
    }

    /**
     * @param  \GdImage|resource  $img
     */
    private function drawQr(
        $img,
        string $curp,
        int $x,
        int $y,
        int $size,
        string $fgHex,
        string $bgHex
    ): void {
        $payload = strtoupper(trim($curp));
        if ($payload === '') {
            $payload = 'SIN-CURP';
        }

        [$fr, $fg, $fb] = $this->hexToRgb($fgHex);
        [$br, $bg, $bb] = $this->hexToRgb($bgHex);

        $qrCode = new QrCode(
            data: $payload,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: max(32, $size),
            margin: 0,
            foregroundColor: new Color($fr, $fg, $fb),
            backgroundColor: new Color($br, $bg, $bb)
        );
        $result = (new PngWriter())->write($qrCode);
        $qrImg = @imagecreatefromstring($result->getString());
        if ($qrImg === false) {
            return;
        }
        imagecopyresampled($img, $qrImg, $x, $y, 0, 0, $size, $size, imagesx($qrImg), imagesy($qrImg));
        imagedestroy($qrImg);
    }

    /**
     * @param  \GdImage|resource  $img
     */
    private function fillRoundedRect($img, int $x, int $y, int $w, int $h, int $radius, int $color): void
    {
        $radius = max(0, min($radius, (int) floor(min($w, $h) / 2)));
        if ($radius <= 0) {
            imagefilledrectangle($img, $x, $y, $x + $w, $y + $h, $color);

            return;
        }

        imagefilledrectangle($img, $x + $radius, $y, $x + $w - $radius, $y + $h, $color);
        imagefilledrectangle($img, $x, $y + $radius, $x + $w, $y + $h - $radius, $color);
        imagefilledellipse($img, $x + $radius, $y + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($img, $x + $w - $radius, $y + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($img, $x + $radius, $y + $h - $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($img, $x + $w - $radius, $y + $h - $radius, $radius * 2, $radius * 2, $color);
    }

    /**
     * @param  \GdImage|resource  $img
     */
    private function applyRoundedMask($img, int $radius): void
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $radius = max(0, min($radius, (int) floor(min($w, $h) / 2)));
        if ($radius <= 0) {
            return;
        }

        imagealphablending($img, false);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);

        for ($px = 0; $px < $w; $px++) {
            for ($py = 0; $py < $h; $py++) {
                if (! $this->pointInRoundedRect($px, $py, $w, $h, $radius)) {
                    imagesetpixel($img, $px, $py, $transparent);
                }
            }
        }

        imagealphablending($img, true);
    }

    private function pointInRoundedRect(int $px, int $py, int $w, int $h, int $radius): bool
    {
        if ($px >= $radius && $px < $w - $radius) {
            return true;
        }
        if ($py >= $radius && $py < $h - $radius) {
            return true;
        }

        $cx = $px < $radius ? $radius : $w - $radius - 1;
        $cy = $py < $radius ? $radius : $h - $radius - 1;
        $dx = $px - $cx;
        $dy = $py - $cy;

        return ($dx * $dx) + ($dy * $dy) <= ($radius * $radius);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Object-fit: cover into the target box.
     *
     * @param  \GdImage|resource  $dest
     * @param  \GdImage|resource  $src
     */
    private function copyCover($dest, $src, int $x, int $y, int $w, int $h): void
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw < 1 || $sh < 1) {
            return;
        }

        $scale = max($w / $sw, $h / $sh);
        $cw = (int) round($w / $scale);
        $ch = (int) round($h / $scale);
        $sx = (int) max(0, ($sw - $cw) / 2);
        $sy = (int) max(0, ($sh - $ch) / 2);

        imagecopyresampled($dest, $src, $x, $y, $sx, $sy, $w, $h, $cw, $ch);
    }

    /**
     * Object-fit: contain into the target box (same as the editor graphics).
     *
     * @param  \GdImage|resource  $dest
     * @param  \GdImage|resource  $src
     */
    private function copyContain($dest, $src, int $x, int $y, int $w, int $h): void
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw < 1 || $sh < 1) {
            return;
        }

        $scale = min($w / $sw, $h / $sh);
        $dw = max(1, (int) round($sw * $scale));
        $dh = max(1, (int) round($sh * $scale));
        $dx = $x + (int) round(($w - $dw) / 2);
        $dy = $y + (int) round(($h - $dh) / 2);

        imagecopyresampled($dest, $src, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
    }

    /**
     * @param  \GdImage|resource  $img
     */
    private function drawText(
        $img,
        string $text,
        int $x,
        int $y,
        float $size,
        int $color,
        string $fontPath,
        int $maxWidth = 0,
        string $align = 'left'
    ): void {
        if (is_file($fontPath) && function_exists('imagettftext')) {
            $lines = $maxWidth > 0
                ? $this->wrapText($text, $size, $fontPath, $maxWidth)
                : (preg_split("/\r\n|\n|\r/", $text) ?: [$text]);
            $metrics = $this->fontLineMetrics($size, $fontPath);
            $lineHeight = $size * 1.1;
            $leading = $lineHeight - ($metrics['ascent'] + $metrics['descent']);
            $baseY = $y + $metrics['ascent'] + ($leading / 2);

            foreach ($lines as $index => $line) {
                $ink = $this->textInkBox($line === '' ? ' ' : $line, $size, $fontPath);
                $lineX = (float) $x;
                if ($maxWidth > 0) {
                    if ($align === 'center') {
                        $lineX = $x + ($maxWidth - $ink['width']) / 2;
                    } elseif ($align === 'right') {
                        $lineX = $x + $maxWidth - $ink['width'];
                    }
                }
                imagettftext(
                    $img,
                    $size,
                    0,
                    (int) round($lineX - $ink['left']),
                    (int) round($baseY + ($index * $lineHeight)),
                    $color,
                    $fontPath,
                    $line
                );
            }

            return;
        }

        imagestring($img, 5, $x, $y, $this->asciiSafe($text), $color);
    }

    /**
     * @return array{ascent: float, descent: float}
     */
    private function fontLineMetrics(float $size, string $fontPath): array
    {
        $box = function_exists('imagettfbbox')
            ? @imagettfbbox($size, 0, $fontPath, 'HgÁÿ|Éj')
            : false;
        if ($box === false) {
            return ['ascent' => $size * 0.8, 'descent' => $size * 0.2];
        }

        return [
            'ascent' => abs((float) $box[7]),
            'descent' => abs((float) $box[1]),
        ];
    }

    /**
     * @return array{left: float, width: float}
     */
    private function textInkBox(string $text, float $size, string $fontPath): array
    {
        if ($text === '' || ! function_exists('imagettfbbox') || ! is_file($fontPath)) {
            $guess = mb_strlen($text) * $size * 0.55;

            return ['left' => 0.0, 'width' => $guess];
        }
        $box = imagettfbbox($size, 0, $fontPath, $text);
        if ($box === false) {
            $guess = mb_strlen($text) * $size * 0.55;

            return ['left' => 0.0, 'width' => $guess];
        }
        $left = (float) $box[0];
        $right = (float) $box[2];

        return [
            'left' => $left,
            'width' => max(0.0, $right - $left),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function wrapText(string $text, float $size, string $fontPath, int $maxWidth): array
    {
        $paragraphs = preg_split("/\r\n|\n|\r/", $text) ?: [$text];
        $lines = [];
        foreach ($paragraphs as $paragraph) {
            foreach ($this->wrapParagraph($paragraph, $size, $fontPath, $maxWidth) as $line) {
                $lines[] = $line;
            }
        }

        return $lines !== [] ? $lines : [''];
    }

    /**
     * @return array<int, string>
     */
    private function wrapParagraph(string $text, float $size, string $fontPath, int $maxWidth): array
    {
        if ($text === '') {
            return [''];
        }
        if ($this->textWidth($text, $size, $fontPath) <= $maxWidth) {
            return [$text];
        }

        $parts = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $lines = [];
        $current = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $candidate = $current.$part;
            if ($current === '' || $this->textWidth($candidate, $size, $fontPath) <= $maxWidth) {
                $current = $candidate;
                continue;
            }
            if (trim($current) !== '') {
                $lines[] = rtrim($current);
            }
            if ($this->textWidth(ltrim($part), $size, $fontPath) > $maxWidth) {
                foreach ($this->splitLongToken(ltrim($part), $size, $fontPath, $maxWidth) as $chunk) {
                    $lines[] = $chunk;
                }
                $current = '';
            } else {
                $current = ltrim($part);
            }
        }
        if (trim($current) !== '') {
            $lines[] = rtrim($current);
        }

        return $lines !== [] ? $lines : [$text];
    }

    /**
     * @return array<int, string>
     */
    private function splitLongToken(string $token, float $size, string $fontPath, int $maxWidth): array
    {
        $chars = preg_split('//u', $token, -1, PREG_SPLIT_NO_EMPTY) ?: [$token];
        $lines = [];
        $current = '';
        foreach ($chars as $char) {
            $candidate = $current.$char;
            if ($current !== '' && $this->textWidth($candidate, $size, $fontPath) > $maxWidth) {
                $lines[] = $current;
                $current = $char;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines !== [] ? $lines : [$token];
    }

    private function textWidth(string $text, float $size, string $fontPath): float
    {
        if ($text === '' || ! function_exists('imagettfbbox') || ! is_file($fontPath)) {
            return mb_strlen($text) * $size * 0.55;
        }
        $box = imagettfbbox($size, 0, $fontPath, $text);
        if ($box === false) {
            return mb_strlen($text) * $size * 0.55;
        }

        return abs((float) $box[2] - (float) $box[0]);
    }

    /**
     * @param  \GdImage|resource  $img
     */
    private function hexToColor($img, string $hex): int
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return imagecolorallocate($img, $r, $g, $b);
    }

    private function asciiSafe(string $text): string
    {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        return $converted !== false ? $converted : preg_replace('/[^\x20-\x7E]/', '?', $text) ?? $text;
    }
}
