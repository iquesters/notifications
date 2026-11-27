<?php

namespace Iquesters\Notifications\Tests\Feature;

use Iquesters\Notifications\Database\Seeders\NotificationsSeeder;
use Iquesters\Notifications\Models\NotificationLog;
use Iquesters\Notifications\Models\NotificationTemplate;
use Iquesters\Notifications\Notifications;
use Iquesters\Notifications\Support\Templates;
use Iquesters\Notifications\Tests\TestCase;

/** Sending, the wording per organisation, the admin screens and who may use them. */
class NotificationsTest extends TestCase
{
    private function send(int $organisationId, array $recipient = [], array $values = []): void
    {
        Notifications::send('payment.rejected', $organisationId, $recipient + ['email' => 'Rahul@Example.test', 'name' => 'Rahul Sen'], $values + [
            'name' => 'Rahul Sen', 'reference' => 'ORD-0001', 'reason' => '<b>Not</b> in the statement', 'pay_url' => 'https://example.test/pay/1',
        ]);
    }

    public function test_a_message_is_sent_from_the_organisation_with_the_values_filled_in(): void
    {
        $organisation = $this->makeOrganisation();
        Templates::syncDefaults();
        $this->send($organisation->id);

        $log = NotificationLog::query()->sole();
        $this->assertSame([NotificationLog::STATUS_SENT, 'rahul@example.test', 'Payment for ORD-0001 not accepted'], [$log->status, $log->recipient, $log->subject]);
        [$email] = $this->sentEmails();
        $this->assertSame('Payment for ORD-0001 not accepted', $email->getSubject());
        // From the organisation's name (no settings yet), through the app's address.
        $this->assertSame(['TTV Alumni', 'noreply@example.test'], [$email->getFrom()[0]->getName(), $email->getFrom()[0]->getAddress()]);
        $html = $email->getHtmlBody();
        $this->assertStringContainsString('<strong>ORD-0001</strong>', $html);
        $this->assertStringContainsString('href="https://example.test/pay/1"', $html);
        // A value is text, never HTML.
        $this->assertStringContainsString('&lt;b&gt;Not&lt;/b&gt; in the statement', $html);
    }

    public function test_skipped_without_an_address_when_switched_off_or_when_sending_is_off(): void
    {
        $organisation = $this->makeOrganisation();
        Templates::ensureFor($organisation->id);

        $this->send($organisation->id, ['email' => '']);
        NotificationTemplate::query()->where('organisation_id', $organisation->id)->update(['active' => false]);
        $this->send($organisation->id);

        $this->assertSame([['skipped', 'No email address.'], ['skipped', 'This message is switched off.']], NotificationLog::query()->orderBy('id')->get()->map(fn ($l) => [$l->status, $l->error])->all());
        $this->assertSame([], $this->sentEmails());
    }

    public function test_an_organisations_own_wording_and_email_settings(): void
    {
        $this->seed(NotificationsSeeder::class);
        $organisation = $this->makeOrganisation();
        $other = $this->makeOrganisation('Other school');
        $admin = $this->memberOf($organisation, 'admin');

        // Its templates: one row per message, the default wording to start.
        $rows = collect($this->actingAs($admin)->getJson('/notifications/templates/data')->assertOk()->json('response_schema.data'));
        $row = $rows->firstWhere('type', 'payment.rejected');
        $this->assertSame(['Payment rejected', 'Email', 'Default wording', 'On'], [$row['label'], $row['channel'], $row['wording'], $row['status_label']]);

        // Their own wording, the preview with the placeholders, a test to themselves.
        $this->actingAs($admin)->putJson('/notifications/templates/'.$row['uid'], ['active' => true, 'subject' => 'About {{reference}}', 'body' => 'Hello {{name}} from us'])->assertOk()
            ->assertJsonPath('data.wording', 'Your own wording');
        $this->actingAs($admin)->get('/notifications/templates/'.$row['uid'].'/preview')->assertOk()->assertSee('{{reason}}')->assertSee('About ORD-0001');
        $this->actingAs($admin)->postJson('/notifications/templates/'.$row['uid'].'/test')->assertOk()->assertJsonPath('message', 'Test sent to '.$admin->email.'.');

        // Who the emails come from.
        $this->actingAs($admin)->putJson('/notifications/settings/'.$organisation->uid, ['from_name' => 'TTV Alumni Association', 'reply_to' => 'office@ttv.test'])->assertOk();
        $this->send($organisation->id);
        $email = collect($this->sentEmails())->last();
        $this->assertSame(['About ORD-0001', 'TTV Alumni Association', 'office@ttv.test'], [$email->getSubject(), $email->getFrom()[0]->getName(), $email->getReplyTo()[0]->getAddress()]);
        $this->assertStringContainsString('Hello Rahul Sen from us', $email->getHtmlBody());

        // Another organisation still gets the default wording.
        $this->send($other->id);
        $this->assertSame('Payment for ORD-0001 not accepted', collect($this->sentEmails())->last()->getSubject());

        // Back to the default.
        $this->actingAs($admin)->postJson('/notifications/templates/'.$row['uid'].'/reset')->assertOk()->assertJsonPath('data.wording', 'Default wording');
        $this->send($organisation->id);
        $this->assertSame('Payment for ORD-0001 not accepted', collect($this->sentEmails())->last()->getSubject());

        // Sent messages: theirs only.
        $logs = collect($this->actingAs($admin)->getJson('/notifications/logs/data')->assertOk()->json('response_schema.data'));
        $this->assertSame(2, $logs->count());
        $this->assertSame(['Sent', 'success'], [$logs->first()['status_label'], $logs->first()['status_tone']]);
    }

    public function test_an_app_message_is_sent_at_once_from_the_app_without_keeping_the_secret(): void
    {
        app(\Iquesters\Notifications\Support\NotificationTypes::class)->register('auth.sign_in_code', [
            'label' => 'Sign-in code',
            'platform' => true,
            'required' => true,
            'secret' => ['code'],
            'variables' => ['code' => 'The code', 'app' => 'App'],
            'sample' => ['code' => '482913', 'app' => 'TTV Alumni'],
            'defaults' => ['email' => ['subject' => '{{code}} is your {{app}} code', 'body' => 'Your code: **{{code}}**']],
        ]);
        $this->seed(NotificationsSeeder::class);
        $organisation = $this->makeOrganisation();

        // Switched off by a super-admin: it's required, so it still goes.
        NotificationTemplate::query()->whereNull('organisation_id')->where('type', 'auth.sign_in_code')->update(['active' => false]);
        Notifications::sendNow('auth.sign_in_code', null, ['email' => 'new@example.test'], ['code' => '482913', 'app' => 'TTV Alumni']);

        [$email] = $this->sentEmails();
        $this->assertSame('482913 is your TTV Alumni code', $email->getSubject());
        $this->assertStringContainsString('<strong>482913</strong>', $email->getHtmlBody());
        // From the app (no organisation): its name in the header and footer.
        $this->assertStringContainsString('Sent by', $email->getHtmlBody());
        // The log never has the code.
        $log = NotificationLog::query()->sole();
        $this->assertSame([NotificationLog::STATUS_SENT, '•••• is your TTV Alumni code', '••••'], [$log->status, $log->subject, $log->variables['code']]);

        // Not in an organisation's templates (only the platform's).
        $admin = $this->memberOf($organisation, 'admin');
        $types = collect($this->actingAs($admin)->getJson('/notifications/templates/data')->assertOk()->json('response_schema.data'))->pluck('type');
        $this->assertNotContains('auth.sign_in_code', $types->all());
        $this->assertContains('payment.rejected', $types->all());
    }

    public function test_an_organisation_message_shows_the_organisation_and_an_app_message_the_app(): void
    {
        $organisation = $this->makeOrganisation('TTV Alumni Association');
        Templates::syncDefaults();
        $this->send($organisation->id);
        $html = collect($this->sentEmails())->last()->getHtmlBody();
        $this->assertStringContainsString('TTV Alumni Association', $html);
        $this->assertStringContainsString('Sent by TTV Alumni Association via', $html);
        // The platform's credit, linked.
        $this->assertStringContainsString('href="https://iquesters.com"', $html);
        $this->assertStringContainsString('Platform developed by Iquesters Solutions LLP', $html);
    }

    public function test_an_organisation_sends_through_its_own_email_account(): void
    {
        $this->seed(NotificationsSeeder::class);
        $organisation = $this->makeOrganisation();
        $admin = $this->memberOf($organisation, 'admin');
        $own = [
            'send_via' => 'own', 'from_name' => 'TTV Alumni Association', 'from_address' => 'Office@TTV.test',
            // Nothing listens there: sending fails at once (as a wrong host or password would).
            'smtp_host' => '127.0.0.1', 'smtp_port' => 1, 'smtp_encryption' => 'none', 'smtp_username' => 'office@ttv.test',
        ];

        // The password is needed the first time.
        $this->actingAs($admin)->putJson('/notifications/settings/'.$organisation->uid, $own)->assertStatus(422)->assertJsonValidationErrors('smtp_password');
        $this->actingAs($admin)->putJson('/notifications/settings/'.$organisation->uid, $own + ['smtp_password' => 's3cret'])->assertOk();
        // Kept when left empty; never shown again.
        $this->actingAs($admin)->putJson('/notifications/settings/'.$organisation->uid, $own)->assertOk();
        $settings = \Iquesters\Notifications\Models\NotificationChannel::for($organisation->id, 'email');
        $this->assertSame(['s3cret', 'office@ttv.test'], [$settings->setting('smtp_password'), $settings->setting('from_address')]);
        $this->assertStringNotContainsString('s3cret', (string) \Illuminate\Support\Facades\DB::table('notification_channels')->value('settings'));
        $this->actingAs($admin)->get('/notifications/settings/'.$organisation->uid)->assertOk()->assertDontSee('s3cret');

        // Its emails go through its account, from its address: here the account fails, which is recorded (nothing breaks).
        [, $from] = \Iquesters\Notifications\Support\OrganisationMailer::for($organisation->id);
        $this->assertSame('office@ttv.test', $from);
        Templates::syncDefaults();
        $this->send($organisation->id);
        $log = NotificationLog::query()->sole();
        $this->assertSame(NotificationLog::STATUS_FAILED, $log->status);
        $this->assertNotEmpty($log->error);
        $this->assertSame([], $this->sentEmails());
        // The test button says why.
        $this->actingAs($admin)->postJson('/notifications/settings/'.$organisation->uid.'/test')->assertStatus(422)
            ->assertJsonPath('success', false);

        // Back to the app's account: sent again (through the app's mailer).
        $this->actingAs($admin)->putJson('/notifications/settings/'.$organisation->uid, ['send_via' => 'app'])->assertOk();
        $this->actingAs($admin)->postJson('/notifications/settings/'.$organisation->uid.'/test')->assertOk();
        $this->assertCount(1, $this->sentEmails());
    }

    public function test_a_failed_email_is_tried_again_and_an_admin_can_send_it_again(): void
    {
        $this->seed(NotificationsSeeder::class);
        $organisation = $this->makeOrganisation();
        $admin = $this->memberOf($organisation, 'admin');
        // Its own account, where nothing listens: every try fails.
        $this->actingAs($admin)->putJson('/notifications/settings/'.$organisation->uid, [
            'send_via' => 'own', 'from_address' => 'office@ttv.test', 'smtp_host' => '127.0.0.1', 'smtp_port' => 1,
            'smtp_encryption' => 'none', 'smtp_username' => 'office@ttv.test', 'smtp_password' => 's3cret',
        ])->assertOk();
        Templates::syncDefaults();
        $this->send($organisation->id);

        // Tried 3 times (the `tries` setting) in the same send, no cron or worker needed; then recorded as failed.
        $log = NotificationLog::query()->sole();
        $this->assertSame([NotificationLog::STATUS_FAILED, 3], [$log->status, (int) $log->attempts]);
        $row = collect($this->actingAs($admin)->getJson('/notifications/logs/data')->assertOk()->json('response_schema.data'))->sole();
        $this->assertSame('1', $row['can_resend']);
        $this->actingAs($admin)->get('/notifications/logs/'.$log->uid.'/summary')->assertOk()->assertSee('Failed')->assertSee('3 tries');

        // Still failing: "Send again" says why.
        $this->actingAs($admin)->postJson('/notifications/logs/'.$log->uid.'/resend')->assertStatus(422)->assertJsonPath('success', false);
        // The account fixed (here: back to the app's): "Send again" sends it.
        $this->actingAs($admin)->putJson('/notifications/settings/'.$organisation->uid, ['send_via' => 'app'])->assertOk();
        $this->actingAs($admin)->postJson('/notifications/logs/'.$log->uid.'/resend')->assertOk()->assertJsonPath('data.status', NotificationLog::STATUS_SENT);
        $this->assertCount(1, $this->sentEmails());
        // Sent: nothing to send again.
        $this->actingAs($admin)->postJson('/notifications/logs/'.$log->uid.'/resend')->assertStatus(422);

        // A message that held a one-time code is never sent again (the code isn't kept).
        app(\Iquesters\Notifications\Support\NotificationTypes::class)->register('auth.sign_in_code', [
            'label' => 'Sign-in code', 'platform' => true, 'required' => true, 'secret' => ['code'],
            'defaults' => ['email' => ['subject' => 'Code', 'body' => '{{code}}']],
        ]);
        $log->update(['type' => 'auth.sign_in_code', 'status' => NotificationLog::STATUS_FAILED]);
        $this->actingAs($admin)->postJson('/notifications/logs/'.$log->uid.'/resend')->assertStatus(422);
        $this->assertCount(1, $this->sentEmails());
    }

    public function test_only_their_admins_and_super_admins(): void
    {
        $this->seed(NotificationsSeeder::class);
        $organisation = $this->makeOrganisation();
        $other = $this->makeOrganisation('Other school');
        Templates::ensureFor($other->id);
        $otherTemplate = NotificationTemplate::query()->where('organisation_id', $other->id)->firstOrFail();

        $member = $this->memberOf($organisation);
        $this->actingAs($member)->getJson('/notifications/templates/data')->assertForbidden();

        $admin = $this->memberOf($organisation, 'admin');
        $this->actingAs($admin)->putJson('/notifications/templates/'.$otherTemplate->uid, ['subject' => 'x', 'body' => 'y'])->assertNotFound();
        $this->actingAs($admin)->putJson('/notifications/settings/'.$other->uid, ['from_name' => 'x'])->assertNotFound();

        // A super-admin without an organisation chosen: the platform's defaults.
        $super = $this->makeUser('super-admin');
        $rows = collect($this->actingAs($super)->getJson('/notifications/templates/data')->assertOk()->json('response_schema.data'));
        $this->assertSame(1, NotificationTemplate::query()->whereNull('organisation_id')->whereIn('uid', $rows->pluck('uid'))->count());
    }
}
