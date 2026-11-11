<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class UtilitiesTest extends TestCase
{
    use RefreshDatabase;

    private function message(array $overrides = []): array
    {
        return [...[
            'sender' => 'admin+test@example.org',
            'recipient' => 'staff+test@example.org',
            'subject' => 'Connection test',
            'body' => "First line.\nSecond line.",
        ], ...$overrides];
    }

    public function test_utilities_require_an_admin_and_navigation_is_hidden_from_other_users(): void
    {
        $this->get('/utilities')->assertRedirect('/login');
        $this->post('/utilities/mail', $this->message())->assertRedirect('/login');
        foreach (array_keys(User::LEVELS) as $level) {
            $this->actingAs(User::factory()->create(['access_level' => $level]));
            $this->get('/utilities')->assertForbidden();
            $this->post('/utilities/mail', $this->message())->assertForbidden();
            $this->get('/forms')->assertOk()->assertDontSee('href="'.route('utilities.index').'"', false);
        }
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/utilities')->assertOk()
            ->assertSee('href="'.route('utilities.index').'"', false)
            ->assertSee('Test mail connection')->assertSee(config('mail.from.address'))->assertSee($admin->email);
    }

    public function test_test_mail_uses_the_configured_mailer_and_all_entered_fields(): void
    {
        config(['mail.default' => 'array']);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/utilities/mail', $this->message())->assertRedirect('/utilities')
            ->assertSessionHas('status', fn ($status) => str_contains($status, 'No email was delivered'));
        $messages = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages->first()->getOriginalMessage();
        $this->assertSame('admin+test@example.org', $email->getFrom()[0]->getAddress());
        $this->assertSame('staff+test@example.org', $email->getTo()[0]->getAddress());
        $this->assertSame('Connection test', $email->getSubject());
        $this->assertSame("First line.\nSecond line.", $email->getTextBody());
        $this->assertSame('adminteam@cgms.edu', config('mail.from.address'));
    }

    public function test_validation_rejects_bad_addresses_and_header_injection_without_sending(): void
    {
        Mail::shouldReceive('raw')->never();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        foreach ([
            ['sender' => 'not-an-email'],
            ['recipient' => 'one@example.org,two@example.org'],
            ['subject' => "Test\r\nBcc: other@example.org"],
            ['body' => ''],
        ] as $invalid) {
            $this->post('/utilities/mail', $this->message($invalid))->assertSessionHasErrors(array_keys($invalid));
        }
    }

    public function test_transport_failure_is_shown_and_the_form_is_preserved_without_leaking_diagnostics(): void
    {
        Mail::shouldReceive('raw')->once()->andThrow(new TransportException('Authentication failed: private diagnostic'));
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->from('/utilities')->followingRedirects()->post('/utilities/mail', $this->message())
            ->assertOk()->assertSee('mail connection test failed')
            ->assertSee('Connection test')->assertDontSee('private diagnostic');
    }

    public function test_log_transport_does_not_claim_to_have_delivered_email(): void
    {
        config(['mail.default' => 'log']);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get('/utilities')->assertOk()->assertSee('This transport does not deliver email.');
        $this->post('/utilities/mail', $this->message())->assertRedirect('/utilities')
            ->assertSessionHas('status', fn ($status) => str_contains($status, 'written to the configured mail log'));
    }

    public function test_delivery_transport_reports_acceptance_not_guaranteed_inbox_delivery(): void
    {
        config(['mail.default' => 'smtp']);
        $email = (new Email)->from('admin@example.org')->to('staff@example.org')->text('Test');
        $sent = new \Illuminate\Mail\SentMessage(new SentMessage($email, Envelope::create($email)));
        Mail::shouldReceive('raw')->once()->andReturn($sent);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/utilities/mail', $this->message())->assertRedirect('/utilities')
            ->assertSessionHas('status', fn ($status) => str_contains($status, 'mailer accepted')
                && str_contains($status, 'does not confirm inbox delivery'));
    }

    public function test_cancelled_mail_is_reported_as_an_error(): void
    {
        Mail::shouldReceive('raw')->once()->andReturnNull();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/utilities/mail', $this->message())->assertSessionHasErrors('mail')->assertSessionMissing('status');
    }
}
