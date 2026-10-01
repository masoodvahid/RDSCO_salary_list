<?php

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Livewire\Members;
use App\Models\User;
use App\Models\UserLog;
use App\Services\InviteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk()->assertSee('ورود به سامانه');
    }

    public function test_user_logs_in_with_an_sms_code(): void
    {
        $user = User::factory()->manager()->create(['mobile' => '09121112233']);

        Livewire::test(Login::class)
            ->set('mobile', '۰۹۱۲۱۱۱۲۲۳۳')
            ->call('sendCode')
            ->assertHasNoErrors()
            ->assertSet('codeSent', true)
            ->set('code', $this->sms->lastCodeFor('09121112233'))
            ->call('verify')
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame('tukahr-login', $this->sms->sent[0]['template']);
        $this->assertSame(['login'], UserLog::where('user_id', $user->id)->pluck('action')->all());

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertSame(['login', 'logout'], UserLog::where('user_id', $user->id)->orderBy('id')->pluck('action')->all());
    }

    public function test_unknown_or_inactive_numbers_get_no_code(): void
    {
        User::factory()->create(['mobile' => '09125556677', 'is_active' => false]);

        Livewire::test(Login::class)->set('mobile', '09120000099')->call('sendCode')->assertHasErrors('mobile');
        Livewire::test(Login::class)->set('mobile', '09125556677')->call('sendCode')->assertHasErrors('mobile');

        $this->assertSame([], $this->sms->sent);
    }

    public function test_wrong_code_does_not_log_in(): void
    {
        User::factory()->create(['mobile' => '09121112233']);
        $component = Livewire::test(Login::class)->set('mobile', '09121112233')->call('sendCode');
        $right = $this->sms->lastCodeFor('09121112233');

        $component->set('code', $right === '000000' ? '111111' : '000000')->call('verify')->assertHasErrors('code');
        $this->assertGuest();
        $this->assertSame('کد واردشده درست نیست.', UserLog::where('action', 'login.failed')->firstOrFail()->meta['reason']);
    }

    public function test_a_code_sent_to_the_old_number_stops_working_when_the_number_changes(): void
    {
        $member = User::factory()->viewer()->create(['mobile' => '09121112233']);
        $component = Livewire::test(Login::class)->set('mobile', '09121112233')->call('sendCode');
        $code = $this->sms->lastCodeFor('09121112233');

        Livewire::actingAs(User::factory()->manager()->create())
            ->test(Members::class)
            ->call('startEdit', $member->id)
            ->set('editMobile', '09129998877')
            ->call('saveEdit')
            ->assertHasNoErrors();
        auth()->logout();

        $component->set('code', $code)->call('verify')->assertHasErrors('code');
        $this->assertGuest();
        $this->assertSame('09129998877', $member->fresh()->mobile);
    }

    public function test_invite_link_prefills_the_invitee(): void
    {
        $manager = User::factory()->manager()->create();
        $invitee = User::factory()->create(['name' => 'کاوه مرادی']);
        $link = app(InviteService::class)->createLink($invitee, $manager);

        $this->get($link)->assertRedirect(route('login'))->assertSessionHas('invite_user_id', $invitee->id);

        $this->withSession(['invite_user_id' => $invitee->id]);
        Livewire::test(Login::class)
            ->assertSet('mobile', $invitee->mobile)
            ->assertSee('کاوه مرادی');
    }

    public function test_invalid_invite_link_is_rejected(): void
    {
        $this->get(route('invite', ['token' => str_repeat('a', 48)]))
            ->assertRedirect(route('login'))
            ->assertSessionMissing('invite_user_id');
    }
}
