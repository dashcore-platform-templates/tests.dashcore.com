<?php

namespace App\Simulation\Exploring;

use App\Simulation\Simulation;

/**
 * What the explorer is told: the app, its people, the step language, what
 * journeys already exist, and where live coverage is thin.
 */
class ExplorePrompt
{
    public function __construct(private Simulation $simulation) {}

    /**
     * @param  list<string>  $gaps  surfaces no journey reaches yet, untested first
     */
    public function build(string $target, ?string $focus, array $gaps): string
    {
        $config = config("simulation.targets.{$target}");
        $drafts = (int) config('simulation.explore.drafts');

        $personas = collect($config['personas'])
            ->map(fn (array $p) => "- {$p['name']} ({$p['role']})")
            ->implode("\n");

        $existing = collect($this->simulation->journeys())
            ->filter(fn (array $entry) => $entry['journey']->target() === $target)
            ->map(fn (array $entry, string $key) => "- {$key}: {$entry['journey']->name()}")
            ->implode("\n");

        $gapList = $gaps === [] ? '(no scan of this app to compare with — use your judgement)' : implode("\n", array_map(fn ($g) => "- {$g}", array_slice($gaps, 0, 60)));
        $focusLine = $focus ? "\nThe person who asked for this exploration wants you to focus on: {$focus}\n" : '';

        return <<<PROMPT
        You are exploring the {$config['name']} app of a fake company, to draft new end-to-end journeys for a never-ending browser simulation. The app's source is the current directory (read it with Read, Glob and Grep; you cannot change anything). It runs at {$config['base_url']}. Fake people sign in and work through it in a real headless Chromium, one journey after another; every journey you draft is first put on trial and must pass several times in a row before it joins the rotation.

        People who use it:
        {$personas}

        Journeys that already exist (do not duplicate them):
        {$existing}

        What no journey reaches yet — the gaps to aim at:
        {$gapList}
        {$focusLine}
        Draft up to {$drafts} journeys, each a piece of real work a person in one of those roles would do. Read the routes, Livewire components and Blade views to get every selector, label and confirmation message exactly right.

        Each journey starts signed in, on the dashboard — never add a login. Steps, each with a plain-English "label" in the present tense that starts with [[who]] (the persona's first name), e.g. "[[who]] opens the deals list":
        - {"do":"visit","path":"/leads"} — a path in the app, starting with /
        - {"do":"fill","target":"#newName","value":"[[name]]"} — type into a field (it is then left, so wire:model.blur fires)
        - {"do":"select","target":"#editStage","value":"proposal"} or {"do":"select","target":"…","option":"Visible text"}
        - {"do":"click","target":"role=button[name=\"Save\"]"} — add "confirm":true when the click opens a browser confirm (wire:confirm)
        - {"do":"see","text":"Deal saved."} — wait until the text is visible
        - {"do":"gone","target":"…"} — wait until an element has left the page
        - {"do":"remember","target":"tbody tr:first-child td:first-child a","as":"lead"} — keep that element's text; later steps, labels and the summary use it as {lead}

        Targets are Playwright selectors: CSS (prefer ids and wire:model/wire:submit attributes, e.g. form[wire\\:submit\\.prevent="createLead"] button[type=submit]), role=button[name="Text"], or :has-text("…"). Fake data comes from tokens, fresh each run: [[name]] [[first]] [[last]] [[email]] [[phone]] [[company]] [[website]] [[sentence]] [[amount]] [[date+N]] [[datetime+N]] (N days from today, for date and datetime-local inputs). The same token twice in one run is the same value.

        Rules:
        - End every journey with a see or gone step that proves the work landed (a confirmation message, the new record in a list).
        - Work only with records the journey creates or picks from a list (remember them); never rely on a record id.
        - Never send anything out of the app: no emailing or texting a lead, no proposals, payment links, "send to" another system, AI research, website scans or playbooks. Never delete anything the journey did not create in the same run.
        - Use only what the role may do (check the middleware and permission checks).
        - Prefer journeys that reach the gaps above, especially ones no test proves.

        Answer with JSON matching the schema: {"journeys":[{"key":"kebab-case-slug","name":"Short name","why":"What it covers and why it matters","roles":["Sales rep"],"weight":1,"summary":"[[who]] … (may use {remembered} names and [[tokens]])","steps":[…]}],"notes":"anything a person should know"}. Weight is 1 to 3: how often a real team does this.
        PROMPT;
    }

    /**
     * The JSON schema the answer must match.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $step = [
            'type' => 'object',
            'required' => ['do', 'label'],
            'properties' => [
                'do' => ['type' => 'string', 'enum' => ['visit', 'fill', 'select', 'click', 'see', 'gone', 'remember']],
                'label' => ['type' => 'string'],
                'path' => ['type' => 'string'],
                'target' => ['type' => 'string'],
                'value' => ['type' => 'string'],
                'option' => ['type' => 'string'],
                'text' => ['type' => 'string'],
                'as' => ['type' => 'string'],
                'confirm' => ['type' => 'boolean'],
            ],
        ];

        return [
            'type' => 'object',
            'required' => ['journeys'],
            'properties' => [
                'journeys' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['key', 'name', 'why', 'roles', 'steps', 'summary'],
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'name' => ['type' => 'string'],
                            'why' => ['type' => 'string'],
                            'roles' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'weight' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3],
                            'summary' => ['type' => 'string'],
                            'steps' => ['type' => 'array', 'items' => $step],
                        ],
                    ],
                ],
                'notes' => ['type' => 'string'],
            ],
        ];
    }
}
