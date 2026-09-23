<?php
declare(strict_types=1);

final class Content
{
    public const HTML_MARKER = '<!-- ScriptCMS HTML -->';

    private const ALLOWED_TAGS = [
        'p','br','strong','b','em','i','u','s','h1','h2','h3','h4','h5','h6',
        'ul','ol','li','blockquote','pre','code','a','img','hr','table','thead',
        'tbody','tr','th','td','div','span'
    ];

    public static function sanitizeHtml(string $html): string
    {
        $html = preg_replace('/<(script|style|iframe|object|embed)[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $html = preg_replace('/<!--(?! ScriptCMS HTML -->).*?-->/is', '', $html) ?? $html;
        $html = strip_tags($html, '<' . implode('><', self::ALLOWED_TAGS) . '>');

        $html = preg_replace_callback('/<([a-z0-9]+)([^>]*)>/i', static function (array $m): string {
            $tag = strtolower($m[1]);
            $attrs = $m[2];

            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                return '';
            }

            if ($tag === 'br' || $tag === 'hr') {
                return '<' . $tag . '>';
            }

            $allowed = match ($tag) {
                'a' => ['href', 'target', 'rel', 'title'],
                'img' => ['src', 'alt', 'title'],
                'td', 'th' => ['colspan', 'rowspan'],
                default => ['title'],
            };

            $out = [];
            if (preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(["\'])(.*?)\2/s', $attrs, $am, PREG_SET_ORDER)) {
                foreach ($am as $attr) {
                    $name = strtolower($attr[1]);
                    $value = html_entity_decode($attr[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                    if (!in_array($name, $allowed, true)) {
                        continue;
                    }

                    if (in_array($name, ['href', 'src'], true)) {
                        if (!self::safeUrl($value, $name === 'src')) {
                            continue;
                        }
                    }

                    if ($name === 'target') {
                        $value = '_blank';
                    }

                    $out[] = $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
                }
            }

            return '<' . $tag . ($out ? ' ' . implode(' ', $out) : '') . '>';
        }, $html) ?? $html;

        // Final defense against event handlers and dangerous protocols.
        $html = preg_replace('/\s+on[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
        $html = preg_replace('/(href|src)\s*=\s*([\'"])\s*javascript:[^\'"]*\2/i', '$1="#"', $html) ?? $html;

        return trim($html);
    }

    private static function safeUrl(string $url, bool $image = false): bool
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, '/')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme === '') {
            return true;
        }

        if ($scheme === 'https' || $scheme === 'http') {
            return true;
        }

        return false;
    }

    public static function render(string $content): string
    {
        if (str_starts_with(ltrim($content), self::HTML_MARKER)) {
            $html = preg_replace('/^\s*<!-- ScriptCMS HTML -->\s*/', '', $content, 1) ?? $content;
            return self::sanitizeHtml($html);
        }

        return self::markdown($content);
    }

    public static function toEditorHtml(string $content): string
    {
        if (str_starts_with(ltrim($content), self::HTML_MARKER)) {
            $html = preg_replace('/^\s*<!-- ScriptCMS HTML -->\s*/', '', $content, 1) ?? $content;
            return self::sanitizeHtml($html);
        }

        return self::markdown($content);
    }

    private static function inline(string $text): string
    {
        $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/', static function ($m) {
            $src = self::safeUrl($m[2], true) ? $m[2] : '#';
            $alt = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
            return '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="' . $alt . '" class="img-fluid">';
        }, $text) ?? $text;

        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function ($m) {
            $href = self::safeUrl($m[2]) ? $m[2] : '#';
            return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
        }, $text) ?? $text;

        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text) ?? $text;
        $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/__(.+?)__/s', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/(?<!_)_([^_\n]+)_(?!_)/', '<em>$1</em>', $text) ?? $text;

        return $text;
    }

    private static function markdown(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $lines = explode("\n", $markdown);
        $out = [];
        $inList = false;
        $listType = '';

        foreach ($lines as $line) {
            $trim = trim($line);

            if ($trim === '') {
                if ($inList) {
                    $out[] = '</' . $listType . '>';
                    $inList = false;
                    $listType = '';
                }
                continue;
            }

            if (preg_match('/^#{1,6}\s+(.+)$/', $trim, $m)) {
                if ($inList) { $out[] = '</' . $listType . '>'; $inList = false; }
                $level = strlen(strtok($trim, ' '));
                $out[] = '<h' . $level . '>' . self::inline(htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8')) . '</h' . $level . '>';
                continue;
            }

            if (preg_match('/^>\s?(.*)$/', $trim, $m)) {
                if ($inList) { $out[] = '</' . $listType . '>'; $inList = false; }
                $out[] = '<blockquote>' . self::inline(htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8')) . '</blockquote>';
                continue;
            }

            if (preg_match('/^[-*]\s+(.+)$/', $trim, $m)) {
                if (!$inList || $listType !== 'ul') {
                    if ($inList) $out[] = '</' . $listType . '>';
                    $out[] = '<ul>';
                    $inList = true;
                    $listType = 'ul';
                }
                $out[] = '<li>' . self::inline(htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8')) . '</li>';
                continue;
            }

            if (preg_match('/^\d+\.\s+(.+)$/', $trim, $m)) {
                if (!$inList || $listType !== 'ol') {
                    if ($inList) $out[] = '</' . $listType . '>';
                    $out[] = '<ol>';
                    $inList = true;
                    $listType = 'ol';
                }
                $out[] = '<li>' . self::inline(htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8')) . '</li>';
                continue;
            }

            if ($inList) {
                $out[] = '</' . $listType . '>';
                $inList = false;
                $listType = '';
            }

            if (preg_match('/^\|(.+)\|$/', $trim)) {
                // Table support is handled as a small block below.
                $out[] = '<p>' . self::inline(htmlspecialchars($trim, ENT_QUOTES, 'UTF-8')) . '</p>';
                continue;
            }

            $out[] = '<p>' . self::inline(htmlspecialchars($trim, ENT_QUOTES, 'UTF-8')) . '</p>';
        }

        if ($inList) $out[] = '</' . $listType . '>';

        $html = implode("\n", $out);

        // Convert simple Markdown tables after escaping/inlining.
        $html = preg_replace_callback('/(?:<p>\|(.+)\|<\/p>\s*){2,}/', static function ($m) {
            $block = preg_replace('/<\/?p>/', '', $m[0]);
            $rows = preg_split('/\s*\n\s*/', trim($block));
            $table = '<table><thead><tr>';
            $first = true;
            foreach ($rows as $row) {
                $cells = array_map('trim', explode('|', trim($row, '| ')));
                if (!$cells || (count($cells) === 1 && $cells[0] === '')) continue;
                if ($first) {
                    foreach ($cells as $cell) $table .= '<th>' . $cell . '</th>';
                    $table .= '</tr></thead><tbody>';
                    $first = false;
                    continue;
                }
                if (count($cells) > 0 && preg_match('/^-{3,}$/', str_replace([':', ' '], '', $cells[0]))) {
                    continue;
                }
                $table .= '<tr>';
                foreach ($cells as $cell) $table .= '<td>' . $cell . '</td>';
                $table .= '</tr>';
            }
            return $table . '</tbody></table>';
        }, $html) ?? $html;

        return self::sanitizeHtml($html);
    }
}
