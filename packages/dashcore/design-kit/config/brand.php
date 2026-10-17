<?php

/*
|--------------------------------------------------------------------------
| Brand
|--------------------------------------------------------------------------
|
| Written by `php artisan design:apply` from the design file's sign-in copy
| and the decisions the components need. Edit the design in
| design.dashcore.com and re-apply rather than editing these by hand.
|
*/

return [
    'name' => env('BRAND_NAME', env('APP_NAME', 'Laravel')),

    // Sign-in copy: the seventh decision.
    'eyebrow' => env('BRAND_EYEBROW'),
    'headline' => env('BRAND_HEADLINE', 'Welcome back.'),
    'tagline' => env('BRAND_TAGLINE', 'Sign in to pick up where you left off.'),
    'footer' => env('BRAND_FOOTER'),

    // Relative to public/, or null for the initial letter.
    'logo' => env('BRAND_LOGO'),

    // Read by the components so the motif and title face match the mockups.
    'motif' => env('DESIGN_MOTIF', 'accent-bar'),
    'second_kind' => env('DESIGN_SECOND_KIND', 'mono'),
    'condensed' => filter_var(env('DESIGN_CONDENSED', false), FILTER_VALIDATE_BOOL),
];
