<?php
declare(strict_types=1);

require_once __DIR__ . '/Content.php';

final class Markdown
{
    public function render(string $markdown): string
    {
        return Content::render($markdown);
    }
}
