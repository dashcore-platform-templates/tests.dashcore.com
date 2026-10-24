<?php

namespace App\Simulation\CustomerJourneys;

use App\Simulation\CustomerJourney;
use App\Simulation\Provisioning\OwnerSignIn;
use App\Simulation\Provisioning\Provisioner;
use App\Simulation\Script;
use Faker\Generator;
use Illuminate\Support\Str;

/**
 * Journey 4: the new customer signs in to their own fleet for the first
 * time, with a password nobody else ever saw.
 *
 * The build gives the owner one way in. Normally it is login's: the build
 * hands login the owner with the Brief, login mails them "Set up your
 * sign-in", and once they choose a password there they land in their ceo,
 * signed in (REQ-116). When login cannot invite them — the fleet has no
 * login, or no Brief, as a fleet sold through dcos has none yet — the build
 * falls back to ceo's own 60-minute password link and warns why. This walks
 * whichever door the build gave them, read off the mail logs (a practice
 * fleet's mail stays in its log): they open the link, choose a password, and
 * land on their dashboard, greeted by name.
 *
 * It follows Journey 3 (Provisioning): the newest fleet dcos was told is
 * built. The link works once, so a second walk of the same fleet fails —
 * which is the point of "first". The door and the password are kept
 * (OwnerSignIn) for the stages after it, which sign the owner in again.
 */
class FirstSignIn extends CustomerJourney
{
    /** @var array<string, mixed>|null the request as dcos reports it */
    private ?array $built = null;

    private string $door = OwnerSignIn::LOGIN;

    public function __construct(private Provisioner $provisioner, private OwnerSignIn $signIns) {}

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
        return ['cockpit', 'login'];
    }

    public function prepare(array $fleet, callable $say): array
    {
        $this->provisioner->source($fleet);
        $this->built = $this->provisioner->show($fleet['apps']['delivery']);
        $root = (string) $fleet['client_root'];
        $cockpit = $this->provisioner->cockpit($this->built, $root);
        $login = $this->provisioner->clientApp($this->built, $root, 'login', "{$this->built['company']}'s login");
        $email = (string) $this->built['owner_email'];

        $this->door = $this->provisioner->mailed($login, $email, '/invitation/') ? OwnerSignIn::LOGIN : OwnerSignIn::CEO;
        $say("{$this->built['company']}'s cockpit is at {$cockpit['base_url']}; its owner is {$email}.");
        $say($this->door === OwnerSignIn::LOGIN
            ? 'login invited them: "Set up your sign-in" is in its mail log.'
            : 'login sent them nothing, so the build fell back to ceo\'s own password link; they come in that way.');

        return ['cockpit' => $cockpit, 'login' => $login];
    }

    public function script(array $people, Generator $faker): Script
    {
        $email = (string) ($this->built['owner_email'] ?? 'owner@example.com');
        $first = Str::before((string) ($this->built['owner_name'] ?? 'Owner'), ' ');
        // Theirs alone, typed twice and never shown.
        $password = Str::password(20, symbols: false);

        if ($this->built !== null) {
            $this->signIns->keep((string) $this->built['install_slug'], $this->door, $email, $password);
        }

        $script = $this->door === OwnerSignIn::LOGIN
            ? (new Script)
                ->on('login')
                // ── Their inbox: login's own mail log ──
                ->mail($email, '\bhttps?://[^\s"\'<>()\[\]]+/invitation/[A-Za-z0-9]+\?email=[^\s"\'<>()\[\]&]+', 'invitation_link', "login's invitation to set up their sign-in arrives for {$email}")
                ->visit('{invitation_link}', 'They open the link')
                ->see('Set up your sign-in', 'login asks them to choose a password')
                ->fill('input[wire\:model="password"]', $password, 'They choose a password')
                ->fill('input[wire\:model="password_confirmation"]', $password, 'They type it again')
                ->click('[data-test="accept-invitation-button"]', 'They set it, and go straight on to their cockpit')
            : (new Script)
                ->on('cockpit')
                // ── Their inbox: the cockpit's own mail log ──
                ->mail($email, '\bhttps?://[^\s"\'<>()\[\]]+/reset-password/[0-9a-f]{64}\?email=[^\s"\'<>()\[\]&]+', 'set_password_link', "ceo's own link to set a password arrives for {$email} (the build fell back)")
                ->visit('{set_password_link}', 'They open the link')
                ->see('Choose a new password', 'Their cockpit asks them to choose a password')
                ->fill('#password', $password, 'They choose a password')
                ->fill('#password_confirmation', $password, 'They type it again')
                ->click('form button[type="submit"]', 'They set it')
                ->see('Sign in', 'They are sent to sign in')
                ->fill('#email', $email, "They enter {$email}")
                ->fill('#password', $password, 'and the password they just chose')
                ->click('form button[type="submit"]', 'They sign in');

        return $script
            ->see(", {$first}", "Their dashboard greets {$first}")
            ->summary($this->door === OwnerSignIn::LOGIN
                ? "{$first} set up their sign-in from login's invitation and landed in their cockpit"
                : "{$first} set their own password from ceo's link — login sent no invitation — and signed in to their cockpit");
    }
}
