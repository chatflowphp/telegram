<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Examples\StarterBot;

/**
 * The catalogue of the bot. Keys live in the code, texts live here; `en` is the fallback locale,
 * so a key missing from `ru` still renders something.
 */
final class StarterBotMessages
{
    /**
     * @var array<string, array<string, string>>
     */
    public const CATALOGUES = [
        'en' => [
            'home.title' => 'Starter Bot',
            'home.body' => 'This minimal example shows a command, a callback route, localization and one validation-backed scene.',
            'home.settings' => 'Open settings',
            'settings.title' => 'Settings',
            'settings.phone' => 'Phone: {phone}',
            'settings.phone_missing' => 'Not set',
            'settings.body' => 'Use the button below to update the saved phone number.',
            'settings.update' => 'Update phone',
            'settings.home' => 'Back to home',
            'phone.title' => 'Profile phone',
            'phone.ask' => "Profile phone\n\nSend your phone in +79991234567 format, or /cancel.",
            'phone.invalid' => 'Use +79991234567.',
            'phone.saved' => 'Phone saved.',
            'phone.unchanged' => 'Phone unchanged.',
            'ack.settings' => 'Opening settings',
            'ack.phone' => 'Updating phone',
            'ack.language' => 'Language changed',
            'language.switch' => 'Русский',
            'fallback' => 'Type /start to open the starter bot.',
        ],
        'ru' => [
            'home.title' => 'Стартовый бот',
            'home.body' => 'Минимальный пример: команда, кнопка, локализация и одна сцена с проверкой ввода.',
            'home.settings' => 'Открыть настройки',
            'settings.title' => 'Настройки',
            'settings.phone' => 'Телефон: {phone}',
            'settings.phone_missing' => 'не указан',
            'settings.body' => 'Кнопка ниже меняет сохранённый номер телефона.',
            'settings.update' => 'Изменить телефон',
            'settings.home' => 'На главную',
            'phone.title' => 'Телефон профиля',
            'phone.ask' => "Телефон профиля\n\nОтправьте номер в формате +79991234567 или /cancel.",
            'phone.invalid' => 'Нужен формат +79991234567.',
            'phone.saved' => 'Телефон сохранён.',
            'phone.unchanged' => 'Телефон не изменился.',
            'ack.settings' => 'Открываю настройки',
            'ack.phone' => 'Меняем телефон',
            'ack.language' => 'Язык изменён',
            'language.switch' => 'English',
            'fallback' => 'Отправьте /start, чтобы открыть бота.',
        ],
    ];
}
