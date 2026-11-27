<?php
/**
 * Chat markup
 *
 * One template for both surfaces. Everything dynamic is escaped, and the
 * copy the script needs is carried in `data-*` attributes so the page works
 * under a strict Content-Security-Policy (no inline JavaScript, no inline
 * event handlers). The browser paints answers with `textContent`.
 *
 * @license GPLv3
 */

namespace Chatbot;

final class View
{
    /**
     * @param array  $actor
     * @param string $surface  opac|admin
     * @param string $endpoint absolute URL of the JSON endpoint
     * @param string $assets   absolute URL of plugins/chatbot/assets/
     * @param array  $config
     * @return string
     */
    public static function render(array $actor, string $surface, string $endpoint, string $assets, array $config = []): string
    {
        $config = $config === [] ? Config::all() : array_merge(Config::defaults(), $config);
        $locale = Lang::locale($config);
        $role = Intent::normaliseRole((string)($actor['role'] ?? 'guest'));
        $roleLabel = Actor::label($actor, $locale);

        $hint = '';

        if ($role === Policy::GUEST) {
            $hint = Lang::t($locale, 'ui_hint_guest');
        } elseif ($role === Policy::MEMBER) {
            $hint = Lang::t($locale, 'ui_hint_member');
        }

        if (Policy::isWrite($role === Policy::MEMBER ? Intent::MY_EXTEND : Intent::LOAN_CHECKOUT)
            && !Policy::writeAllowed($role, $role === Policy::MEMBER ? Intent::MY_EXTEND : Intent::LOAN_CHECKOUT, $config)
            && $role !== Policy::GUEST) {
            $hint = trim($hint . ' ' . Lang::t($locale, 'ui_write_off'));
        }

        $strings = [
            'send' => Lang::t($locale, 'ui_send'),
            'thinking' => Lang::t($locale, 'ui_thinking'),
            'network' => Lang::t($locale, 'ui_network_error'),
            'expired' => Lang::t($locale, 'ui_session_expired'),
            'confirm' => Lang::t($locale, 'ui_confirm'),
            'cancel' => Lang::t($locale, 'ui_cancel'),
            'cancelled' => Lang::t($locale, 'ui_cancelled'),
            'chips' => Lang::t($locale, 'ui_chips'),
            'greeting' => Lang::t($locale, 'greet_' . $role, self::placeholders($actor)),
            'placeholder' => Lang::t($locale, 'ui_placeholder'),
        ];

        $chips = Intent::chips($role, $locale);

        $subtitleKey = 'ui_subtitle_' . $role;
        $subtitle = Lang::t($locale, $subtitleKey);

        $html = [];
        $html[] = '<link rel="stylesheet" href="' . self::e($assets . 'chatbot.css') . '">';
        $html[] = '<section class="chatbot" data-chatbot'
            . ' data-endpoint="' . self::e($endpoint) . '"'
            . ' data-csrf="' . self::e(Http::token()) . '"'
            . ' data-role="' . self::e($role) . '"'
            . ' data-locale="' . self::e($locale) . '"'
            . ' data-strings="' . self::e(self::json($strings)) . '"'
            . ' data-chips="' . self::e(self::json($chips)) . '"'
            . '>';
        $html[] = '  <header class="chatbot__head">';
        $html[] = '    <div class="chatbot__titles">';
        $html[] = '      <h2 class="chatbot__title">' . self::e(Lang::t($locale, 'ui_title')) . '</h2>';
        $html[] = '      <p class="chatbot__subtitle">' . self::e($subtitle) . '</p>';
        $html[] = '    </div>';
        $html[] = '    <span class="chatbot__role chatbot__role--' . self::e($role) . '">' . self::e($roleLabel) . '</span>';
        $html[] = '  </header>';

        if ($hint !== '') {
            $html[] = '  <p class="chatbot__hint">' . self::e($hint) . '</p>';
        }

        $html[] = '  <ol class="chatbot__log" role="log" aria-live="polite" aria-relevant="additions"></ol>';
        $html[] = '  <div class="chatbot__chips" aria-label="' . self::e(Lang::t($locale, 'ui_chips')) . '"></div>';
        $html[] = '  <form class="chatbot__form" autocomplete="off">';
        $html[] = '    <label class="chatbot__sr" for="chatbot-input-' . self::e($role) . '">' . self::e(Lang::t($locale, 'ui_placeholder')) . '</label>';
        $html[] = '    <input id="chatbot-input-' . self::e($role) . '" class="chatbot__input" type="text" maxlength="500" placeholder="' . self::e(Lang::t($locale, 'ui_placeholder')) . '">';
        $html[] = '    <button class="chatbot__send" type="submit">' . self::e(Lang::t($locale, 'ui_send')) . '</button>';
        $html[] = '  </form>';
        $html[] = '  <p class="chatbot__meta">' . self::e(Lang::t($locale, 'ui_powered')) . '</p>';
        $html[] = '</section>';
        $html[] = '<script src="' . self::e($assets . 'chatbot.js') . '" defer></script>';

        return implode("\n", $html);
    }

    /**
     * @param array $actor
     * @return array
     */
    private static function placeholders(array $actor): array
    {
        $name = trim((string)($actor['name'] ?? ''));

        if ($name === '') {
            $name = (string)($actor['member_id'] ?? '');
        }

        return ['name' => $name === '' ? 'there' : $name];
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function json($value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
