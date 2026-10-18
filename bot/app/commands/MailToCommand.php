<?php

class MailToCommand
{
    private const FIELD_KEY_CHAT = 'chat';

    private Bot $bot;

    public function __construct(Bot $bot)
    {
        $this->bot = $bot;
    }

    /**
     * Отправка сообщения пользователю от имени администратора
     *
     * @param array $message Входящее сообщение от Telegram
     * @param string $text Текст команды после /mailto
     */
    public function execute(array $message, string $targetId, string $adminText): void
    {
        $chatId = $message[self::FIELD_KEY_CHAT]['id'];

        // Проверка прав администратора
        if (!$this->bot->isAdmin($chatId)) {
            $this->bot->sendMessage($chatId, "⛔ У вас недостаточно прав для использования этой команды");
            return;
        }

        // Проверка $message и chat_id
        if (!isset($message['chat']['id'])) {
            $this->bot->sendMessage($chatId, "⛔ Не передан chat_id в сообщении");
            return;
        }

        // Проверка targetId
        if (!is_numeric($targetId) || $targetId <= 0) {
            $this->bot->sendMessage($chatId, "⛔ Некорректный ID пользователя");
            return;
        }

        // Проверка текста сообщения
        $adminText = trim($adminText);
        if ($adminText === "" || mb_strlen($adminText) < 3) {
            $this->bot->sendMessage($chatId, "⛔ Текст сообщения слишком короткий");
            return;
        }

        if (mb_strlen($adminText) > 1000) {
            $this->bot->sendMessage($chatId, "⛔ Текст сообщения слишком длинный");
            return;
        }

        // Получаем данные пользователя из базы
//         $user = DB::selectOne("
//         SELECT chat_id, username
//         FROM users
//         WHERE id = ?
//     ", [$targetId]);
// 
//         if (!$user || !isset($user['chat_id'])) {
//             $this->bot->sendMessage($chatId, "⛔ Пользователь с ID <code>$targetId</code> не найден");
//             return;
//         }

        // Отправка сообщения пользователю
        $this->bot->sendMessage($targetId, "📩 <b>Ответное сообщение:</b>\n$adminText");

        // Подтверждение для администратора
        $this->bot->sendMessage($chatId, "☑️ Сообщение отправлено пользователю <code>$targetId</code>");
    }

}
