<?php

namespace App\Services;

use App\Models\Admin\TeacherActivationCode;

class ActivationCodeService
{
    private const CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    public function generateFor(int $teacherId): string
    {
        do {
            $code = $this->randomCode();
            $hash = hash('sha256', $code);
        } while (TeacherActivationCode::where('code_hash', $hash)->exists());

        TeacherActivationCode::create([
            'teacher_id' => $teacherId,
            'code_hash' => $hash,
            'status' => 'unused',
        ]);

        return $code;
    }

    private function randomCode(): string
    {
        $part = fn () => collect(range(1, 4))
            ->map(fn () => self::CHARS[random_int(0, strlen(self::CHARS) - 1)])
            ->implode('');

        return $part() . '-' . $part();
    }
}