<?php
function bersihkan_html_tulisan($html) {
    if (!class_exists('DOMDocument')) {
        return nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
    $allowedTags = ['p','br','h2','h3','h4','strong','b','em','i','u','s','strike',
        'ul','ol','li','blockquote','a','img','span','div','hr','sub','sup','font'];
    $allowedColors = ['#000000','#ffffff','#dc2626','#ea580c','#ca8a04','#16a34a',
        '#0891b2','#2563eb','#7c3aed','#db2777','#475569'];

    $dom = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?><div id="editor-root">'.$html.'</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $root = $dom->getElementById('editor-root');
    if (!$root) return '';

    $ambilUkuranGambar = function (DOMElement $img): ?string {
        $kandidat = [
            $img->getAttribute('data-width'),
            $img->getAttribute('width'),
        ];

        $style = $img->getAttribute('style');
        if ($style && preg_match('/(?:^|;|\s)width\s*:\s*([0-9]+(?:\.[0-9]+)?(?:px|%))/i', $style, $m)) {
            $kandidat[] = $m[1];
        }

        foreach ($kandidat as $nilai) {
            $nilai = trim((string) $nilai);
            if ($nilai === '') continue;

            if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*%$/', $nilai, $m)) {
                $angka = (float) $m[1];
                if ($angka >= 5 && $angka <= 100) {
                    return rtrim(rtrim(number_format($angka, 2, '.', ''), '0'), '.') . '%';
                }
            } elseif (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*px$/i', $nilai, $m)) {
                $angka = (float) $m[1];
                if ($angka >= 20 && $angka <= 3000) {
                    return rtrim(rtrim(number_format($angka, 2, '.', ''), '0'), '.') . 'px';
                }
            } elseif (preg_match('/^[0-9]+$/', $nilai)) {
                $angka = (float) $nilai;
                if ($angka >= 20 && $angka <= 3000) {
                    return (int) $angka . 'px';
                }
            }
        }

        return null;
    };

    $clean = function($node) use (&$clean, $dom, $allowedTags, $allowedColors, $ambilUkuranGambar) {
        for ($child = $node->firstChild; $child;) {
            $next = $child->nextSibling;
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (!in_array($tag, $allowedTags, true)) {
                    if (in_array($tag, ['script','style','iframe','object','embed','form'], true)) {
                        $node->removeChild($child);
                    } else {
                        while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                        $node->removeChild($child);
                    }
                } else {
                    $keep = [];
                    if ($tag === 'a') {
                        $href = trim($child->getAttribute('href'));
                        if (preg_match('#^(https?://|mailto:)#i', $href)) $keep['href'] = $href;
                        $keep['target'] = '_blank';
                        $keep['rel'] = 'noopener noreferrer';
                    } elseif ($tag === 'img') {
                        $src = $child->getAttribute('src');
                        if (preg_match('#^uploads/artikel/[a-zA-Z0-9_-]+\.(jpg|jpeg|png|webp|gif)$#i', $src)) {
                            $keep['src'] = $src;
                            $keep['alt'] = substr($child->getAttribute('alt'), 0, 180);
                        } else {
                            $node->removeChild($child);
                            $child = $next;
                            continue;
                        }
                        $ukuran = $ambilUkuranGambar($child);
                        if ($ukuran !== null) {
                            $keep['data-width'] = $ukuran;
                            $keep['width'] = $ukuran;
                            $keep['style'] = 'width: ' . $ukuran . '; height: auto;';
                        }
                        $keep['loading'] = 'lazy';
                    } elseif ($tag === 'font') {
                        $color = strtolower($child->getAttribute('color'));
                        if (preg_match('/^#[0-9a-f]{6}$/', $color)) $keep['color'] = $color;
                        $size = $child->getAttribute('size');
                        if (preg_match('/^[1-7]$/', $size)) $keep['size'] = $size;
                    } elseif ($tag === 'span' || $tag === 'p' || $tag === 'div') {
                        $style = $child->getAttribute('style');
                        if (preg_match('/text-align\s*:\s*(left|center|right|justify)/i', $style, $m))
                            $keep['style'] = 'text-align:'.$m[1];
                        if (preg_match('/color\s*:\s*(#[0-9a-f]{6})/i', $style, $m))
                            $keep['style'] = trim(($keep['style'] ?? '').';color:'.strtolower($m[1]), ';');
                    }
                    while ($child->attributes->length) $child->removeAttributeNode($child->attributes->item(0));
                    foreach ($keep as $key => $value) $child->setAttribute($key, $value);
                    $clean($child);
                }
            } elseif (!($child instanceof DOMText)) {
                $node->removeChild($child);
            }
            $child = $next;
        }
    };
    $clean($root);
    $result = '';
    foreach ($root->childNodes as $child) $result .= $dom->saveHTML($child);
    return trim($result);
}