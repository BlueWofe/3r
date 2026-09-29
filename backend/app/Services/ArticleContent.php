<?php

namespace App\Services;

use App\Models\Entity;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class ArticleContent
{
    public function sanitize(string $body): string
    {
        $config = (new HtmlSanitizerConfig)->withMaxInputLength(500000)->allowLinkSchemes(['http', 'https', 'mailto'])->allowRelativeLinks()->allowMediaSchemes([])->allowRelativeMedias();
        foreach (['p', 'h2', 'h3', 'strong', 'em', 'u', 's', 'blockquote', 'ul', 'ol', 'li', 'br', 'hr'] as $tag) {
            $config = $config->allowElement($tag);
        }
        foreach (['script', 'style', 'iframe', 'object', 'embed', 'template', 'svg', 'math'] as $tag) {
            $config = $config->dropElement($tag);
        }
        $config = $config->allowElement('a', ['href', 'title'])->allowElement('img', ['src', 'alt'])->withAttributeSanitizer(new PublicImageAttributeSanitizer);
        $html = (new HtmlSanitizer($config))->sanitize($body);

        // Invalid or revoked images must not leave empty image placeholders.
        return preg_replace('~<img\b(?![^>]*\bsrc=)[^>]*>~i', '', $html);
    }

    public function write(array $data, Entity $entity, string $actor): array
    {
        $old = $entity->data ?? [];
        $data['body_format'] = $data['body_format'] ?? ($old['body_format'] ?? 'text');
        $data['author_name'] = trim((string) ($data['author_name'] ?? '')) ?: ($old['author_name'] ?? ($entity->exists ? '協會編輯部' : $actor));
        if (empty($data['published_at'])) {
            $data['published_at'] = $old['published_at'] ?? (($old['status'] ?? '') === 'published' ? $entity->created_at?->toIso8601String() : null);
        }
        if ($data['published_at']) {
            $data['published_at'] = CarbonImmutable::parse($data['published_at'], 'Asia/Taipei')->setTimezone('Asia/Taipei')->toIso8601String();
        }
        if ($data['status'] === 'published' && ! $data['published_at'] && ($old['status'] ?? '') !== 'published') {
            $data['published_at'] = now('Asia/Taipei')->toIso8601String();
        }
        if ($data['body_format'] === 'html') {
            $data['body'] = $this->sanitize($data['body']);
            $text = html_entity_decode(strip_tags($data['body']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (trim(str_replace("\u{00A0}", ' ', $text)) === '' && ! str_contains($data['body'], '<img ')) {
                throw ValidationException::withMessages(['body' => '文章內容不可為空白。']);
            }
        } elseif (trim($data['body']) === '') {
            throw ValidationException::withMessages(['body' => '文章內容不可為空白。']);
        }

        return $data;
    }

    public function date(Entity $entity): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($entity->data['published_at'] ?? $entity->created_at, 'Asia/Taipei')->setTimezone('Asia/Taipei');
        } catch (\Throwable) {
            return CarbonImmutable::parse($entity->created_at)->setTimezone('Asia/Taipei');
        }
    }

    public function visible(Entity $entity): bool
    {
        return ($entity->data['status'] ?? '') === 'published' && $this->date($entity)->lte(now());
    }

    public function payload(Entity $entity): array
    {
        $data = $entity->publicData();
        $data['body_format'] = ($data['kind'] ?? '') === 'product' ? 'text' : ($data['body_format'] ?? 'text');
        $body = $data['body'] ?? '';
        $text = str_replace(["\r\n", "\r"], "\n", htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $paragraphs = array_map(fn ($paragraph) => '<p>'.str_replace("\n", '<br>', $paragraph).'</p>', preg_split('/\n\s*\n/', $text));
        $data['body_html'] = $data['body_format'] === 'html' ? $this->sanitize($body) : implode('', $paragraphs);
        if ($data['body_format'] === 'html') {
            $data['body'] = $data['body_html'];
        }
        $data['author_name'] = $data['author_name'] ?? '協會編輯部';
        $data['published_at'] = $this->date($entity)->toIso8601String();

        return $data;
    }

    public function searchText(Entity $entity): string
    {
        return html_entity_decode(strip_tags($this->payload($entity)['body_html']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
