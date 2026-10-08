<?php

declare(strict_types=1);

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Limpa HTML vindo do editor de texto rico com lista de permissões.
 *
 * Mantém só formatação básica (parágrafos, negrito, itálico, sublinhado, riscado,
 * listas, citação, código e links http/https/mailto). Remove scripts, estilos,
 * eventos (onclick…), iframes, imagens, formulários e qualquer atributo — exceto
 * o href de links, que recebe target="_blank" e rel="noopener noreferrer nofollow".
 *
 * Modo modelo de e-mail ($template = true): aceita também títulos (h2/h3) e links
 * cujo endereço é uma variável, ex.: href="{{chamado.link}}".
 */
final class HtmlSanitizer
{
    private const ALLOWED = ['p', 'br', 'strong', 'em', 'u', 's', 'ul', 'ol', 'li', 'a', 'blockquote', 'code', 'pre'];
    private const RENAME  = ['b' => 'strong', 'i' => 'em', 'strike' => 's', 'del' => 's', 'div' => 'p', 'h1' => 'p', 'h2' => 'p', 'h3' => 'p', 'h4' => 'p', 'h5' => 'p', 'h6' => 'p'];
    private const DROP    = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'option', 'svg', 'math', 'head', 'title', 'meta', 'link', 'base', 'template', 'noscript', 'img', 'video', 'audio', 'canvas'];

    public static function clean(string $html, bool $template = false): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"?><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->getElementById('__root');
        if ($root === null) {
            return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
        }
        self::walk($root, $doc, $template);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        if ($template) {
            // O libxml codifica "{{" em href (%7B%7B); devolve a variável legível.
            $out = preg_replace('/%7B%7B\s*([a-z_.]+)\s*%7D%7D/i', '{{$1}}', $out) ?? $out;
        }
        // Remove parágrafos vazios repetidos no fim.
        $out = preg_replace('#(<p>(\s|&nbsp;|<br>)*</p>\s*)+$#u', '', trim($out)) ?? $out;
        return trim($out);
    }

    /** Texto puro (para e-mail, IA, prévias). */
    public static function toText(string $html): string
    {
        $t = preg_replace(['#<br\s*/?>#i', '#</(p|li|blockquote|pre)>#i', '#<(ul|ol)[^>]*>#i', '#<li[^>]*>#i'], ["\n", "\n", "\n", '• '], $html) ?? $html;
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/[ \t]+\n/", "\n", $t) ?? $t;
        return trim(preg_replace("/\n{3,}/", "\n\n", $t) ?? $t);
    }

    /** Há texto visível? (evita respostas só com <p><br></p>) */
    public static function hasText(string $html): bool
    {
        return trim(str_replace("\u{00A0}", ' ', self::toText($html))) !== '';
    }

    private static function walk(DOMNode $node, DOMDocument $doc, bool $template = false): void
    {
        $allowed = $template ? [...self::ALLOWED, 'h2', 'h3'] : self::ALLOWED;
        $renames = $template ? ['h1' => 'h2', 'h4' => 'h3', 'h5' => 'h3', 'h6' => 'h3'] + self::RENAME : self::RENAME;
        unset($renames['h2'], $renames['h3']);
        if (!$template) {
            $renames += ['h2' => 'p', 'h3' => 'p'];
        }
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof DOMElement) {
                continue; // texto
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP, true)) {
                $node->removeChild($child);
                continue;
            }
            if (isset($renames[$tag])) {
                $child = self::rename($child, $renames[$tag], $doc);
                $tag = $renames[$tag];
            }
            if (!in_array($tag, $allowed, true)) {
                // Desembrulha: mantém o conteúdo, descarta a tag.
                self::walk($child, $doc, $template);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            $href = $tag === 'a' ? trim($child->getAttribute('href')) : '';
            foreach (iterator_to_array($child->attributes) as $attr) {
                $child->removeAttribute($attr->nodeName);
            }
            if ($tag === 'a') {
                $isVar = $template && preg_match('/^\{\{\s*[a-z_.]+\s*\}\}$/', $href);
                if (!$isVar && !preg_match('#^(https?://|mailto:)#i', $href)) {
                    self::walk($child, $doc, $template);
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }
                $child->setAttribute('href', $href);
                $child->setAttribute('target', '_blank');
                $child->setAttribute('rel', 'noopener noreferrer nofollow');
            }
            self::walk($child, $doc, $template);
        }
    }

    private static function rename(DOMElement $el, string $tag, DOMDocument $doc): DOMElement
    {
        $new = $doc->createElement($tag);
        while ($el->firstChild) {
            $new->appendChild($el->firstChild);
        }
        $el->parentNode->replaceChild($new, $el);
        return $new;
    }
}
