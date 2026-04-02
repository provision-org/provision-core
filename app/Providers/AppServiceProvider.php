<?php

namespace App\Providers;

use App\Services\ModuleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureMailDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureMailDefaults(): void
    {
        $replyTo = config('mail.reply_to.address');

        if ($replyTo) {
            Event::listen(MessageSending::class, function (MessageSending $event) use ($replyTo) {
                $message = $event->message;

                if (empty($message->getReplyTo())) {
                    $message->replyTo($replyTo, config('mail.reply_to.name'));
                }
            });
        }
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null
        );
    }
}
