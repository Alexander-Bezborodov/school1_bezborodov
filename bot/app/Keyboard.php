<?php

class Keyboard
{
    /**
     * Создает inline-клавиатуру
     *
     * @param array $buttons массив кнопок, каждая строка - массив кнопок в ряд
     * @return array
     */
    public static function inline(array $buttons): array
    {
        return [
            'inline_keyboard' => $buttons
        ];
    }

    /**
     * Создает кнопку для inline клавиатуры
     *
     * @param string $text текст кнопки
     * @param string $callback_data callback_data
     * @param string $color цвет кнопки
     * @return array
     */
    public static function button(string $text, string $callback_data, $color = null): array
    {
        $button = [
            'text' => $text,
            'callback_data' => $callback_data,
        ];

        if ($color !== null) {
            $button['style'] = $color;
        }

        return $button;
    }

    /**
     * Создает reply клавиатуру
     *
     * @param array $buttons массив кнопок, каждая строка - массив кнопок в ряд
     * @param bool $resize изменять размер клавиатуры
     * @param bool $oneTime скрывать после нажатия
     * @return array
     */
    public static function reply(array $buttons, bool $resize = true, bool $oneTime = false): array
    {
        return [
            'keyboard' => $buttons,
            'resize_keyboard' => $resize,
            'one_time_keyboard' => $oneTime
        ];
    }

    /**
     * Создает кнопку для reply клавиатуры
     *
     * @param string $text текст кнопки
     * @return array
     */
    public static function replyButton(string $text): array
    {
        return ['text' => $text];
    }
}

// Уточнена ответственность компонента: клавиатура бота.
