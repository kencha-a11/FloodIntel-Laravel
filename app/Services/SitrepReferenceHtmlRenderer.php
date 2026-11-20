<?php

namespace App\Services;

/**
 * Renders the SITREP document as the office's exact HTML form (owner directive,
 * 2026-11-20): the shipped reference at resources/sitrep/reference is served
 * byte-for-byte except the memorandum's placeholder runs, which are filled
 * from the frozen snapshot. The form is a static pdf2htmlEX export — its
 * tables are picture-backed fixed rows and cannot carry live figures; the
 * DOCX export remains the live-data artifact.
 */
final class SitrepReferenceHtmlRenderer
{
    public function render(array $doc): string
    {
        $html = file_get_contents(resource_path('sitrep/reference/mdrrmo-sitrep-template.html'));

        // Blank the example event name first: it is the placeholder SUBJECT's
        // continuation. Doing this before injection keeps the phrase intact
        // when a real event title legitimately contains it.
        $html = str_replace('Southwest Monsoon', '', $html);

        $subject = 'Situational Report No. '
            .($doc['issuance']['report_number'] ?? 'XX (unissued draft)')
            .' Re: '
            .($doc['event']['title'] ?? 'No flood event linked to this revision');

        $html = preg_replace_callback(
            '/(<div class="t [^"]*">SUBJECT.*?<span class="ff7">).*?(<\/span>)/s',
            fn (array $m): string => $m[1].e($subject).$m[2],
            $html,
            1,
        );

        $html = preg_replace_callback(
            '/(<div class="t [^"]*">DATE.*?<span class="ff5">).*?(<\/span>)/s',
            fn (array $m): string => $m[1].e($doc['meta']['snapshot_as_of']).$m[2],
            $html,
            1,
        );

        return str_replace(
            '</head>',
            '<meta name="sitrep-document" content="mdrrmo-reference"></head>',
            $html,
        );
    }
}
