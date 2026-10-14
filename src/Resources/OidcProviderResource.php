<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Resources;

use Dskripchenko\LaravelAdmin\Action\Button;
use Dskripchenko\LaravelAdmin\Field\Input;
use Dskripchenko\LaravelAdmin\Field\Password;
use Dskripchenko\LaravelAdmin\Field\Repeater;
use Dskripchenko\LaravelAdmin\Field\Select;
use Dskripchenko\LaravelAdmin\Field\Switcher;
use Dskripchenko\LaravelAdmin\Panel\Panels;
use Dskripchenko\LaravelAdmin\Resource\ActionFailedException;
use Dskripchenko\LaravelAdmin\Resource\Resource;
use Dskripchenko\LaravelAdmin\Table\TableColumn;
use Dskripchenko\LaravelAdminOidc\Contracts\RoleCatalog;
use Dskripchenko\LaravelAdminOidc\Flow\SignInFlow;
use Dskripchenko\LaravelAdminOidc\Http\Controllers\SsoController;
use Dskripchenko\LaravelAdminOidc\Models\OidcProvider;
use Dskripchenko\LaravelAdminOidc\Protocol\Discovery;
use Dskripchenko\LaravelAdminOidc\Protocol\Jwks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * "SSO providers": the OpenID Connect providers the panels sign in with.
 *
 * Lists the panels' own providers; a tenant's provider (with an owner) is
 * edited where the owner lives, in the host — see `resource.show_owned`.
 */
class OidcProviderResource extends Resource
{
    public static string $model = OidcProvider::class;

    public static string $icon = 'key';

    public static ?string $group = 'Система';

    public static function slug(): string
    {
        return 'oidc-providers';
    }

    public static function permission(): string
    {
        return 'admin.oidc.providers';
    }

    public static function label(): string
    {
        return __('SSO-провайдеры');
    }

    /** @return Builder<OidcProvider> */
    public function modelQuery(): Builder
    {
        $query = OidcProvider::query();

        return (bool) config('admin-oidc.resource.show_owned', false) ? $query : $query->whereNull('owner_type');
    }

    public function fields(): array
    {
        return [
            Input::make('name')->title(__('Название'))->span(6)
                ->placeholder('Keycloak')
                ->help(__('Подпись кнопки на экране входа: «Войти через …»'))
                ->rules(['required', 'string', 'max:100'])->required(),
            Select::make('panel')->title(__('Панель'))->span(6)
                ->options($this->panelOptions())
                ->rules(['required', 'string', 'in:'.implode(',', array_keys($this->panelOptions()))])->required()
                ->help(__('Куда входят через этого провайдера')),
            Input::make('issuer')->title(__('Issuer (адрес провайдера)'))->span(12)
                ->placeholder('https://keycloak.example.com/realms/company')
                ->help(__('Адрес, по которому провайдер публикует /.well-known/openid-configuration. Для Keycloak — адрес realm. Redirect URI для настроек клиента в провайдере: :uris', ['uris' => $this->redirectUris()]))
                ->rules(['required', 'url', 'max:500'])->required(),
            Input::make('client_id')->title(__('Client ID'))->span(6)
                ->rules(['required', 'string', 'max:255'])->required(),
            Password::make('client_secret')->title(__('Client secret'))->span(6)
                ->help(__('Пусто на редактировании — оставить текущий. Для публичного клиента — пусто'))
                ->rules(['nullable', 'string', 'max:2000']),
            Input::make('scopes')->title(__('Scopes'))->span(6)
                ->placeholder('openid email profile')
                ->rules(['nullable', 'string', 'max:500']),
            Input::make('roles_claim')->title(__('Где роли в токене'))->span(6)
                ->placeholder('realm_access.roles')
                ->help(__('Путь через точку: Keycloak — realm_access.roles или resource_access.<client>.roles, Entra ID — roles, Authentik — groups'))
                ->rules(['required', 'string', 'max:255'])->required(),
            Select::make('roles_source')->title(__('Откуда брать роли'))->span(6)
                ->options([OidcProvider::ROLES_FROM_ID_TOKEN => __('Из ID-токена'), OidcProvider::ROLES_FROM_USERINFO => __('Из userinfo')])
                ->default(OidcProvider::ROLES_FROM_ID_TOKEN)
                ->rules(['required', 'in:id_token,userinfo']),
            Repeater::make('role_mapping')->title(__('Маппинг ролей'))->span(12)
                ->fields([
                    Input::make('value')->title(__('Значение в токене'))->span(6)->rules(['required', 'string', 'max:255']),
                    Select::make('role')->title(__('Роль в панели'))->span(6)->options($this->roleOptions())->rules(['required', 'string']),
                ])
                ->help(__('Вход разрешён, только если хотя бы одно значение нашлось здесь; роли учётки заменяются по маппингу при каждом входе')),
            Switcher::make('require_mfa')->title(__('Требовать MFA'))->span(3)
                ->help(__('Отказ, если провайдер не подтвердил второй фактор (claim amr; в Keycloak — маппер «Authentication Method Reference»)')),
            Switcher::make('link_by_email')->title(__('Связывать по email'))->span(3)
                ->default(true)
                ->help(__('Первый вход занимает учётку с тем же email, только если провайдер подтвердил email')),
            Switcher::make('sign_out_at_provider')->title(__('Выходить и у провайдера'))->span(3)
                ->default(true)
                ->help(__('Выход из панели завершает и сессию у провайдера, если он её поддерживает. Адрес возврата для настроек клиента в провайдере (Valid post logout redirect URIs): :uris', ['uris' => $this->postLogoutUris()])),
            Switcher::make('enabled')->title(__('Включён'))->span(3)->default(true),
        ];
    }

    public function columns(): array
    {
        return [
            TableColumn::make('name')->label(__('Название'))->search(),
            TableColumn::make('panel')->label(__('Панель')),
            TableColumn::make('issuer')->label(__('Issuer')),
            TableColumn::make('enabled')->asBoolean(__('Включён'), __('Выключен')),
        ];
    }

    public function actions(): array
    {
        return [
            Button::make(__('Проверить'))->withName('check')->method('check')
                ->position(['row'])->permission(self::permission()),
        ];
    }

    /**
     * Reads the provider's discovery document and JWKS: the settings either
     * work or say why not before anyone tries to sign in.
     *
     * @param  list<int|string>  $ids
     * @param  array<string, mixed>  $payload
     */
    public function check(array $ids, array $payload = []): int
    {
        $ok = 0;
        foreach (OidcProvider::query()->whereIn('id', $ids)->get() as $provider) {
            try {
                $metadata = app(Discovery::class)->for($provider, fresh: true);
                $keys = app(Jwks::class)->keysFor($metadata, null);
                if ($keys === []) {
                    throw new \RuntimeException(__('у провайдера нет ключей подписи'));
                }
                $ok++;
            } catch (\Throwable $e) {
                throw new ActionFailedException($provider->name.': '.$e->getMessage());
            }
        }

        return $ok;
    }

    public function fillModel(Model $model, array $data): void
    {
        if (($data['client_secret'] ?? '') === '') {
            unset($data['client_secret']);
        }
        $allowed = array_keys($this->roleOptions());
        $mapping = [];
        foreach ((array) ($data['role_mapping'] ?? []) as $row) {
            $value = trim((string) ($row['value'] ?? ''));
            $role = (string) ($row['role'] ?? '');
            if ($value === '' || $role === '') {
                continue;
            }
            if (! in_array($role, $allowed, true)) {
                throw ValidationException::withMessages(['role_mapping' => __('Роль «:role» нельзя выдавать через этого провайдера.', ['role' => $role])]);
            }
            $mapping[] = ['value' => $value, 'role' => $role];
        }
        $data['role_mapping'] = $mapping;
        $data['issuer'] = rtrim((string) ($data['issuer'] ?? $model->getAttribute('issuer')), '/');
        $data['scopes'] = trim((string) ($data['scopes'] ?? '')) !== '' ? $data['scopes'] : 'openid email profile';

        parent::fillModel($model, $data);
        $model->save();
    }

    /** @return array<string, string> */
    protected function panelOptions(): array
    {
        $out = [];
        foreach ((array) config('admin-oidc.panels', ['admin']) as $id) {
            if (is_string($id) && Panels::registry()->has($id)) {
                $out[$id] = $id;
            }
        }

        return $out;
    }

    /** @return array<string, string> slug => name */
    protected function roleOptions(): array
    {
        $provider = new OidcProvider(['panel' => (string) array_key_first($this->panelOptions())]);

        return app(RoleCatalog::class)->roles($provider)->mapWithKeys(static fn ($role): array => [(string) $role->slug => (string) $role->name])->all();
    }

    private function redirectUris(): string
    {
        $uris = [];
        foreach (array_keys($this->panelOptions()) as $id) {
            $panel = Panels::registry()->get($id);
            if ($panel !== null) {
                $uris[] = SignInFlow::redirectUri($panel);
            }
        }

        return implode(', ', $uris);
    }

    private function postLogoutUris(): string
    {
        $uris = [];
        foreach (array_keys($this->panelOptions()) as $id) {
            $panel = Panels::registry()->get($id);
            if ($panel !== null) {
                $uris[] = SsoController::loginUrl($panel);
            }
        }

        return implode(', ', $uris);
    }
}
