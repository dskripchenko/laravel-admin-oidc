<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Auth;

/**
 * What the person on the login page reads for a reason. The technical detail
 * goes to the log; here — what happened to them and what to do.
 */
final class SignInMessages
{
    public static function for(string $reason, string $detail = ''): string
    {
        return match ($reason) {
            'no-role' => __('Нет прав на доступ: у вашей учётной записи в провайдере нет ролей для этой панели. Обратитесь к администратору.'),
            'email-taken' => __('Учётная запись с таким email уже есть, но связать её со входом через SSO нельзя. Обратитесь к администратору.'),
            'mfa-required' => __('Провайдер не подтвердил второй фактор, а для этой панели он обязателен. Войдите в провайдере с двухфакторной проверкой.'),
            'account-disabled' => __('Учётная запись отключена.'),
            'panel-forbidden' => __('Нет доступа к этой панели.'),
            'flow-expired' => __('Вход через SSO не завершён или устарел — попробуйте ещё раз.'),
            'provider-denied' => __('Провайдер не разрешил вход: :reason', ['reason' => $detail !== '' ? $detail : __('причина не указана')]),
            default => __('Не удалось войти через SSO: провайдер недоступен или настроен неверно. Обратитесь к администратору.'),
        };
    }
}
