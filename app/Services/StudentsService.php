<?php

namespace App\Services;

class StudentsService
{
    /**
     * Generate a private image url
     */
    public function generatePrivateImageUrl($grade, $group, $photo)
    {
        $photoPath = ($grade && $group && $photo)
            ? 'photos/students/' . rawurlencode($grade)
            . '/' . rawurlencode($group)
            . '/' . rawurlencode($photo)
            : 'photos/students/default.png';

        return $photoPath;
    }
}
