<?php

namespace App\Livewire\Auth;

use App\Models\Invitation;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Services\Otp\OtpService;
use App\Services\UserActivity;
use App\Support\Digits;
use App\Support\Mobile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Login extends Component
{
    public string $mobile = '';

    public string $code = '';

    public bool $codeSent = false;

    #[Locked]
    public ?int $challengeId = null;

    #[Locked]
    public ?string $inviteName = null;

    public function mount(): void
    {
        $userId = session('invite_user_id');
        if ($userId && ($user = User::find($userId)) && $user->is_active) {
            $this->mobile = $user->mobile;
            $this->inviteName = $user->name;
        }
    }

    public function sendCode(OtpService $otp): void
    {
        $this->mobile = Mobile::normalize($this->mobile);
        $this->resetErrorBag();

        if (! Mobile::isValid($this->mobile)) {
            $this->addError('mobile', 'شماره موبایل را به شکل ۰۹۱۲۱۲۳۴۵۶۷ وارد کنید.');

            return;
        }

        $key = 'otp-login:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->addError('mobile', 'تعداد درخواست‌ها زیاد است. '.Digits::toPersian(RateLimiter::availableIn($key)).' ثانیه دیگر تلاش کنید.');

            return;
        }
        RateLimiter::hit($key, 600);

        $user = User::where('mobile', $this->mobile)->where('is_active', true)->first();
        if (! $user) {
            $this->addError('mobile', 'این شماره در سامانه ثبت نشده است. از مدیر سامانه بخواهید شما را دعوت کند.');

            return;
        }

        $challenge = $otp->issue($user, OtpChallenge::PURPOSE_LOGIN, [], request()->ip(), 'mobile');

        $this->challengeId = $challenge->id;
        $this->codeSent = true;
        $this->code = '';
    }

    public function verify(OtpService $otp, UserActivity $activity)
    {
        $this->resetErrorBag();

        $challenge = $this->challengeId ? OtpChallenge::where('purpose', OtpChallenge::PURPOSE_LOGIN)->find($this->challengeId) : null;
        if (! $challenge) {
            $this->codeSent = false;
            throw ValidationException::withMessages(['mobile' => 'دوباره کد بگیرید.']);
        }

        try {
            $otp->verify($challenge, $this->code);
        } catch (ValidationException $e) {
            if ($challenge->user) {
                $activity->record($challenge->user, 'login.failed', $challenge->user, ['reason' => collect($e->errors())->flatten()->first()]);
            }

            throw $e;
        }

        $user = $challenge->user;
        if (! $user || ! $user->is_active) {
            throw ValidationException::withMessages(['code' => 'حساب شما غیرفعال است.']);
        }

        Auth::login($user, remember: true);
        session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        $activity->record($user, 'login', $user);

        if ($inviteId = session('invite_id')) {
            Invitation::whereKey($inviteId)->update(['last_used_at' => now()]);
        }
        session()->forget(['invite_id', 'invite_user_id']);

        return $this->redirectIntended(route('dashboard'), navigate: false);
    }

    public function changeNumber(): void
    {
        $this->codeSent = false;
        $this->code = '';
        $this->challengeId = null;
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.auth.login')->layout('layouts::guest')->title('ورود');
    }
}
