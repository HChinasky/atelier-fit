<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * The published legal documents as plain HTML pages (/legal/privacy-policy and
 * friends). The PDFs in public/legal stay as they are; these pages exist
 * because a crawler — Google's OAuth verification, for one — wants a readable
 * web page, not a download. Public and outside every feature flag, like
 * /data-deletion: the policy must be reachable whatever state the site is in.
 */
class LegalDocumentController extends Controller
{
    public function show(string $document): View
    {
        $titles = config('legal.documents', []);
        $path = base_path("docs/legal/{$document}.md");

        abort_unless(array_key_exists($document, $titles) && is_file($path), 404);

        $markdown = (string) file_get_contents($path);

        // Drop the internal "draft, needs a lawyer" banner that heads the file:
        // everything up to and including the first horizontal rule.
        if (str_starts_with($markdown, '>')) {
            $markdown = (string) preg_replace('/\A.*?^---\s*$/ms', '', $markdown, 1);
        }

        return view('legal.document', [
            'withoutOverlays' => true,
            'title' => $titles[$document],
            'html' => Str::markdown(trim($markdown), [
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]),
        ]);
    }
}
