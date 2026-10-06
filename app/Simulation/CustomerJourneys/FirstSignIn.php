<?php

namespace App\Simulation\CustomerJourneys;

use App\Simulation\CustomerJourney;
use App\Simulation\Provisioning\Provisioner;
use App\Simulation\Script;
use Faker\Generator;
use Illuminate\Support\Str;

/**
 * Journey 4: the new customer signs in to their own fleet for the first
 * time, with a password nobody else ever saw.
 *
 * The build's last step made the owner an account on their cockpit and had
 * the cockpit mail them a link to set a password (`app:make-admin --invite`).
 * A practice fleet's mail stays in its log, so the owner's inbox is the
 * cockpit's own mail log. They open the link, choose a password, sign in
 * with it, and land on their dashboard, greeted by name.
 *
 * It follows Journey 3 (Provisioning): the newest fleet dcos was told is
 * built. The link works once, so a second walk of the same fleet fails —
 * which is the point of "first".
 */
class FirstSignIn extends CustomerJourney
{
    /** @var array<string, mixed>|null the request as dcos reports it */
    private ?array $built = null;

    public function __construct(private Provisioner $provisioner) {}

    public function name(): string
    {
        return 'The owner sets their password from the invitation, signs in, and lands in their cockpit';
    }

    public function guards(): array
    {
        return ['delivery'];
    }

    public function providesApps(): array
    {
        return ['cockpit'];
    }

    public function prepare(array $fleet, callable $say): array
    {
        $this->provisioner->source($fleet);
        $this->built = $this->provisioner->show($fleet['apps']['delivery']);
        $cockpit = $this->provisioner->cockpit($this->built, (string) $fleet['client_root']);
        $say("{$this->built['company']}'s cockpit is at {$cockpit['base_url']}; its owner is {$this->built['owner_email']}.");

        return ['cockpit' => $cockpit];
    }

    public function script(array $people, Generator $faker): Script
    {
        $email = (string) ($this->built['owner_email'] ?? 'owner@example.com');
        $first = Str::before((string) ($this->built['owner_name'] ?? 'Owner'), ' ');
        // Theirs alone, typed twice and never shown.
        $password = Str::password(20, symbols: false);

        return (new Script)
            ->on('cockpit')
            // ── Their inbox: the cockpit's own mail log ──
            ->mail($email, '\bhttps?://[^\s"\'<>()\[\]]+/reset-password/[0-9a-f]{64}\?email=[^\s"\'<>()\[\]&]+', 'set_password_link', "The invitation to set a password arrives for {$email}")
            ->visit('{set_password_link}', 'They open the link')
            ->see('Choose a new password', 'Their cockpit asks them to choose a password')
            ->fill('#password', $password, 'They choose a password')
            ->fill('#password_confirmation', $password, 'They type it again')
            ->click('form button[type="submit"]', 'They set it')
            ->see('Sign in', 'They are sent to sign in')
            ->fill('#email', $email, "They enter {$email}")
            ->fill('#password', $password, 'and the password they just chose')
            ->click('form button[type="submit"]', 'They sign in')
            ->see(", {$first}", "Their dashboard greets {$first}")
            ->summary("{$first} set their own password from the invitation and signed in to their cockpit");
    }
}
