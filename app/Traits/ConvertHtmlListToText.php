<?php

namespace App\Traits;

trait ConvertHtmlListToText
{
    /**
     * Convert HTML ordered/unordered lists to Word-compatible format
     */
    public function ConvertHtmlListToText($html)
    {
        $result = $this->convertHtmlListToTextRaw($html);

        // Re-escape XML special characters (& < > " ') for PhpWord TemplateProcessor
        // Because TemplateProcessor::setValue() does raw str_replace into XML
        return htmlspecialchars($result, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function convertHtmlListToTextRaw($html)
    {
        if (empty($html)) {
            return '';
        }

        // Remove HTML tags except list items - handle Quill editor format
        $html = preg_replace('/<span[^>]*class=["\']ql-ui["\'][^>]*>.*?<\/span>/i', '', $html);
        $html = preg_replace('/<span[^>]*contenteditable=["\']false["\'][^>]*><\/span>/i', '', $html);

        // Handle ordered lists (ol with li elements containing data-list="ordered")
        if (preg_match('/<ol[^>]*>(.*?)<\/ol>/is', $html, $olMatch)) {
            $listContent = $olMatch[1];
            if (preg_match_all('/<li[^>]*(?:data-list=["\']ordered["\'])?[^>]*>(.*?)<\/li>/is', $listContent, $matches)) {
                $result = '';
                foreach ($matches[1] as $index => $item) {
                    // Clean up the item content
                    $cleanItem = preg_replace('/<span[^>]*class=["\']ql-ui["\'][^>]*>.*?<\/span>/i', '', $item);
                    $cleanItem = strip_tags($cleanItem);
                    $cleanItem = html_entity_decode($cleanItem, ENT_QUOTES, 'UTF-8');
                    $cleanItem = trim($cleanItem);

                    if (!empty($cleanItem)) {
                        $result .= ($index + 1) . '. ' . $cleanItem . "\n";
                    }
                }
                return trim($result);
            }
        }

        // Handle unordered lists (ul with li elements containing data-list="bullet")
        if (preg_match('/<ul[^>]*>(.*?)<\/ul>/is', $html, $ulMatch)) {
            $listContent = $ulMatch[1];
            if (preg_match_all('/<li[^>]*(?:data-list=["\']bullet["\'])?[^>]*>(.*?)<\/li>/is', $listContent, $matches)) {
                $result = '';
                foreach ($matches[1] as $item) {
                    // Clean up the item content
                    $cleanItem = preg_replace('/<span[^>]*class=["\']ql-ui["\'][^>]*>.*?<\/span>/i', '', $item);
                    $cleanItem = strip_tags($cleanItem);
                    $cleanItem = html_entity_decode($cleanItem, ENT_QUOTES, 'UTF-8');
                    $cleanItem = trim($cleanItem);

                    if (!empty($cleanItem)) {
                        $result .= '• ' . $cleanItem . "\n";
                    }
                }
                return trim($result);
            }
        }

        // Handle standalone li elements with data-list="ordered" (Quill format without ol wrapper)
        if (preg_match_all('/<li[^>]*data-list=["\']ordered["\'][^>]*>(.*?)<\/li>/is', $html, $matches)) {
            $result = '';
            foreach ($matches[1] as $index => $item) {
                // Clean up the item content
                $cleanItem = preg_replace('/<span[^>]*class=["\']ql-ui["\'][^>]*>.*?<\/span>/i', '', $item);
                $cleanItem = strip_tags($cleanItem);
                $cleanItem = html_entity_decode($cleanItem, ENT_QUOTES, 'UTF-8');
                $cleanItem = trim($cleanItem);

                if (!empty($cleanItem)) {
                    $result .= ($index + 1) . '. ' . $cleanItem . "\n";
                }
            }
            return trim($result);
        }

        // Handle standalone li elements with data-list="bullet" (Quill format without ul wrapper)
        if (preg_match_all('/<li[^>]*data-list=["\']bullet["\'][^>]*>(.*?)<\/li>/is', $html, $matches)) {
            $result = '';
            foreach ($matches[1] as $item) {
                // Clean up the item content
                $cleanItem = preg_replace('/<span[^>]*class=["\']ql-ui["\'][^>]*>.*?<\/span>/i', '', $item);
                $cleanItem = strip_tags($cleanItem);
                $cleanItem = html_entity_decode($cleanItem, ENT_QUOTES, 'UTF-8');
                $cleanItem = trim($cleanItem);

                if (!empty($cleanItem)) {
                    $result .= '• ' . $cleanItem . "\n";
                }
            }
            return trim($result);
        }

        // If no list found, just clean the HTML
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text); // Normalize whitespace
        $text = trim($text);

        return $text;
    }
}
