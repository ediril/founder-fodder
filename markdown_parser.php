<?php
/**
 * Simple markdown to HTML parser
 * Handles basic markdown syntax for displaying inspiration stories
 */

function parse_markdown(string $markdown): string {
    $lines = explode("\n", $markdown);
    $html = '';
    $in_paragraph = false;
    $in_list = false;

    foreach ($lines as $line) {
        $trimmed = trim($line);

        // Skip empty lines
        if (empty($trimmed)) {
            if ($in_paragraph) {
                $html .= "</p>\n";
                $in_paragraph = false;
            }
            if ($in_list) {
                $html .= "</ul>\n";
                $in_list = false;
            }
            continue;
        }

        // Headers
        if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $matches)) {
            if ($in_paragraph) {
                $html .= "</p>\n";
                $in_paragraph = false;
            }
            if ($in_list) {
                $html .= "</ul>\n";
                $in_list = false;
            }
            $level = strlen($matches[1]);
            $text = htmlspecialchars($matches[2]);
            $html .= "<h{$level}>{$text}</h{$level}>\n";
            continue;
        }

        // Unordered lists
        if (preg_match('/^[\*\-]\s+(.+)$/', $trimmed, $matches)) {
            if ($in_paragraph) {
                $html .= "</p>\n";
                $in_paragraph = false;
            }
            if (!$in_list) {
                $html .= "<ul>\n";
                $in_list = true;
            }
            $text = parse_inline_markdown($matches[1]);
            $html .= "<li>{$text}</li>\n";
            continue;
        }

        // Ordered lists
        if (preg_match('/^\d+\.\s+(.+)$/', $trimmed, $matches)) {
            if ($in_paragraph) {
                $html .= "</p>\n";
                $in_paragraph = false;
            }
            if (!$in_list) {
                $html .= "<ol>\n";
                $in_list = true;
            }
            $text = parse_inline_markdown($matches[1]);
            $html .= "<li>{$text}</li>\n";
            continue;
        }

        // Blockquotes
        if (preg_match('/^>\s+(.+)$/', $trimmed, $matches)) {
            if ($in_paragraph) {
                $html .= "</p>\n";
                $in_paragraph = false;
            }
            if ($in_list) {
                $html .= "</ul>\n";
                $in_list = false;
            }
            $text = parse_inline_markdown($matches[1]);
            $html .= "<blockquote>{$text}</blockquote>\n";
            continue;
        }

        // Regular paragraphs
        if ($in_list) {
            $html .= "</ul>\n";
            $in_list = false;
        }
        if (!$in_paragraph) {
            $html .= "<p>";
            $in_paragraph = true;
        } else {
            $html .= " ";
        }
        $html .= parse_inline_markdown($trimmed);
    }

    // Close any open tags
    if ($in_paragraph) {
        $html .= "</p>\n";
    }
    if ($in_list) {
        $html .= "</ul>\n";
    }

    return $html;
}

/**
 * Parse inline markdown elements (bold, italic, links, code)
 */
function parse_inline_markdown(string $text): string {
    // Links [text](url)
    $text = preg_replace_callback(
        '/\[([^\]]+)\]\(([^\)]+)\)/',
        function($matches) {
            $link_text = htmlspecialchars($matches[1]);
            $url = htmlspecialchars($matches[2]);
            return "<a href=\"{$url}\">{$link_text}</a>";
        },
        $text
    );

    // Bold **text** or __text__
    $text = preg_replace('/\*\*([^\*]+)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $text);

    // Italic *text* or _text_
    $text = preg_replace('/\*([^\*]+)\*/', '<em>$1</em>', $text);
    $text = preg_replace('/_([^_]+)_/', '<em>$1</em>', $text);

    // Inline code `code`
    $text = preg_replace_callback(
        '/`([^`]+)`/',
        function($matches) {
            return '<code>' . htmlspecialchars($matches[1]) . '</code>';
        },
        $text
    );

    // If no special formatting was applied, escape HTML
    if (strpos($text, '<') === false) {
        $text = htmlspecialchars($text);
    }

    return $text;
}
