<?php

namespace App\Services;

use App\Models\Entity;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

class PublicImageAttributeSanitizer implements AttributeSanitizerInterface
{
    public function getSupportedElements(): ?array
    {
        return ['img'];
    }

    public function getSupportedAttributes(): ?array
    {
        return ['src'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        if (! preg_match('~^/api/v1/files/([1-9][0-9]*)/download$~D', $value, $match)) {
            return null;
        }
        $file = Entity::where('type', 'files')->find($match[1]);
        if (! $file || ($file->data['visibility'] ?? '') !== 'public') {
            return null;
        }
        $path = $file->data['path'] ?? '';
        if (! $path || ! Storage::exists($path)) {
            return null;
        }

        return in_array(Storage::mimeType($path), ['image/jpeg', 'image/png', 'image/webp'], true) ? $value : null;
    }
}
