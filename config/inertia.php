<?php

/*
|--------------------------------------------------------------------------
| Inertia
|--------------------------------------------------------------------------
|
| Only the page lookup is overridden here; everything else keeps the package defaults. The pages
| live in resources/js/pages (lowercase), while the package looks in resources/js/Pages. A
| case-insensitive disk (macOS, Windows) hides the difference, but a case-sensitive one (Linux,
| CI, Docker) reports "Inertia page component file ... does not exist" in every page test.
|
*/

$pagePaths = [resource_path('js/pages')];
$pageExtensions = ['js', 'jsx', 'svelte', 'ts', 'tsx', 'vue'];

return [

    'page_paths' => $pagePaths,

    'page_extensions' => $pageExtensions,

    'testing' => [

        'ensure_pages_exist' => true,

        'page_paths' => $pagePaths,

        'page_extensions' => $pageExtensions,

    ],

];
