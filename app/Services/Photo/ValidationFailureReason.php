<?php

namespace App\Services\Photo;

/**
 * Machine-readable reason a photo failed the pose-based suitability gate (9.2).
 * The flow (9.5) maps these to the user-facing UX; here each reason also exposes
 * its i18n translation key so the ValidationResult carries a translatable message
 * rather than a hardcoded string.
 */
enum ValidationFailureReason: string
{
    case NoPerson = 'no_person';
    case MultiplePeople = 'multiple_people';
    case NotFullBody = 'not_full_body';
    case NotFrontal = 'not_frontal';
    case FaceOnly = 'face_only';

    /**
     * Translation key under the "photo_validation" group (lang/<locale>/photo_validation.php).
     */
    public function messageKey(): string
    {
        return 'photo_validation.'.$this->value;
    }
}
