<?php

namespace App\Services;

use App\Models\Entity;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Masterminds\HTML5;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class ArticleContent
{
    public function sanitize(string $body, bool $images = true): string
    {
        $config = (new HtmlSanitizerConfig)->withMaxInputLength(500000)->allowLinkSchemes(['http', 'https', 'mailto'])->allowRelativeLinks()->allowMediaSchemes([])->allowRelativeMedias();
        foreach (['p', 'h2', 'h3', 'strong', 'em', 'u', 's', 'blockquote', 'ul', 'ol', 'li', 'br', 'hr'] as $tag) {
            $config = $config->allowElement($tag);
        }
        foreach (['script', 'style', 'iframe', 'object', 'embed', 'template', 'svg', 'math'] as $tag) {
            $config = $config->dropElement($tag);
        }
        $config = $config->allowElement('a', ['href', 'title']);
        $config = $images ? $config->allowElement('img', ['src', 'alt'])->withAttributeSanitizer(new PublicImageAttributeSanitizer) : $config->dropElement('img');
        $html = (new HtmlSanitizer($config))->sanitize($body);

        // Invalid or revoked images must not leave empty image placeholders.
        return preg_replace('~<img\b(?![^>]*\ssrc="/api/v1/files/[1-9][0-9]*/download")[^>]*>~i', '', $html);
    }

    public function write(array $data, Entity $entity, string $actor): array
    {
        $old = $entity->data ?? [];
        if ($entity->exists && isset($data['version'])) {
            abort_unless((int) $data['version'] === (int) ($old['version'] ?? 1), 409, '文章版本已更新。');
        }
        $data['version'] = $entity->exists ? (int) ($old['version'] ?? 1) + 1 : 1;
        $data['article_type'] = $data['article_type'] ?? ($old['article_type'] ?? (($old['category'] ?? $data['category'] ?? '') === '見證分享' ? 'testimony' : 'news'));
        $data['visibility'] = $data['visibility'] ?? ($old['visibility'] ?? 'public');
        $explicitGroups = array_key_exists('group_ids', $data);
        $data['group_ids'] = $data['group_ids'] ?? ($old['group_ids'] ?? []);
        if ($data['kind'] !== 'news' && $data['visibility'] !== 'public') {
            throw ValidationException::withMessages(['visibility' => '僅消息可限定小組。']);
        }
        if ($data['visibility'] === 'groups') {
            if ($explicitGroups || ! $entity->exists) {
                $data['group_ids'] = app(GroupAudience::class)->validate($data['group_ids']);
            }
            if (! $data['group_ids']) {
                throw ValidationException::withMessages(['group_ids' => '請至少選擇一個有效小組。']);
            }
            $format = $data['body_format'] ?? ($old['body_format'] ?? 'text');
            if (isset($data['image_id']) || ($format === 'html' && (new HTML5)->loadHTML($data['body'])->getElementsByTagName('img')->length)) {
                throw ValidationException::withMessages(['body' => '小組消息不支援圖片，請移除圖片。']);
            }
        } else {
            $data['group_ids'] = [];
        }
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
            $data['body'] = $this->sanitize($data['body'], $data['visibility'] !== 'groups');
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
        $data['version'] = $data['version'] ?? 1;
        $data['visibility'] = $data['visibility'] ?? 'public';
        $data['article_type'] = $data['article_type'] ?? (($data['category'] ?? '') === '見證分享' ? 'testimony' : 'news');
        $data['group_ids'] = $data['group_ids'] ?? [];
        $data['group_names'] = app(GroupAudience::class)->names($data['group_ids']);
        if ($data['visibility'] === 'groups') {
            $data['image_id'] = null;
        }
        $data['body_format'] = ($data['kind'] ?? '') === 'product' ? 'text' : ($data['body_format'] ?? 'text');
        $body = $data['body'] ?? '';
        $text = str_replace(["\r\n", "\r"], "\n", htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $paragraphs = array_map(fn ($paragraph) => '<p>'.str_replace("\n", '<br>', $paragraph).'</p>', preg_split('/\n\s*\n/', $text));
        $data['body_html'] = $data['body_format'] === 'html' ? $this->sanitize($body, $data['visibility'] !== 'groups') : implode('', $paragraphs);
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
