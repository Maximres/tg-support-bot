<?php

return [
    'start' => 'Добрый день! Чем я могу вам помочь?',
    'ban_bot' => '⛔️ Пользователь заблокировал бота!',
    'ban_user' => '⛔️ Вы были заблокированы по решению администрации бота!',

    'but_ban_user_true' => '🚫 Заблокировать',
    'but_ban_user_false' => '🔓 Разблокировать',

    'but_trust_user_true' => '✅ Открыть доступ к кодам',
    'but_trust_user_false' => '🔒 Отозвать доступ к кодам',

    // Команды /code, /building_code, /org_link — текстовый аналог кнопок
    'command_code_value' => ':type: <code>:code</code>',
    'command_code_not_set' => ':type ещё не задан(а).',

    // Личный доступ к кодам и орг. информации по кнопке
    'but_access_open_org_link' => 'Открыть',
    'access_message_text' => "Нажмите на нужную кнопку, чтобы получить код от сейфа, код от здания или ссылку на орг. информацию.\nЕсли доступ ещё не открыт, вы получите соответствующее уведомление — обратитесь к администратору.",
    'access_not_trusted' => 'Доступ не открыт. Обратитесь к администратору.',
    'access_value_not_set' => ':type ещё не задан(а).',
    'access_value_reveal' => ':type: :value',
    'access_org_link_not_set' => 'Ссылка на орг. информацию ещё не задана.',
    'access_org_link_message' => 'Актуальная ссылка на орг. информацию:',
    'access_rotated' => '🔐 :type обновлён(а).',

    'but_close_topic' => '🚪 Закрыть обращение',
    'message_close_topic' => 'Ваше обращение закрыто!',

    'but_request_phone' => '📱 Поделиться номером телефона',
    'request_phone_message' => 'Пожалуйста, поделитесь своим номером телефона для более быстрой связи.',
    'phone_already_provided' => 'Ваш номер телефона уже сохранен: <b>:phone</b>',
    
    'but_request_phone_from_group' => '📱 Запросить номер телефона',
    'request_phone_from_group' => 'Менеджер запросил ваш номер телефона для более быстрой связи. Пожалуйста, поделитесь номером.',
    'phone_request_sent' => '✅ Запрос на предоставление номера телефона отправлен клиенту.',
    'phone_already_in_group' => '📱 Номер телефона клиента уже сохранен: <b>:phone</b>',

    // Описания команд для клиентов
    'command_start_description' => 'Начать работу с ботом',
    'command_phone_description' => 'Поделиться номером телефона',
    'command_my_data_description' => 'Показать мои данные',
    'command_edit_name_description' => 'Изменить ФИО',
    'command_edit_phone_description' => 'Изменить телефон',
    'command_edit_email_description' => 'Изменить Email',
    'command_code_description' => 'Получить код от сейфа',
    'command_building_code_description' => 'Получить код от здания',
    'command_org_link_description' => 'Получить ссылку на орг. информацию',
    'command_restore_access_description' => 'Восстановить сообщение с кнопками доступа к кодам',

    // Описания команд для администраторов
    'command_contact_description' => 'Показать контактную информацию клиента',
    'command_request_phone_description' => 'Запросить номер телефона у клиента',
    'command_rename_topic_description' => 'Переименовать топик (использование: /rename_topic новое название)',
    'command_restore_topic_name_description' => 'Восстановить название топика по умолчанию',
    'command_set_code_description' => 'Задать новый код сейфа (использование: /set_code значение)',
    'command_set_building_code_description' => 'Задать новый код от здания (использование: /set_building_code значение)',
    'command_set_org_link_description' => 'Задать ссылку на орг. информацию (использование: /set_org_link ссылка)',
    'command_rename_topic_request' => "Пожалуйста, укажите новое название топика после команды:
<code>/rename_topic новое название</code>

Пример: <code>/rename_topic Важное обращение</code>",

    // Установка кодов/ссылки администратором
    'command_admin_only' => '⛔ Эта команда доступна только администраторам группы.',
    'command_admin_check_failed' => '⚠️ Не удалось проверить права администратора, попробуйте ещё раз.',
    'command_set_value_request' => "Пожалуйста, укажите новое значение после команды:
<code>:command значение</code>

Пример: <code>:example</code>",
    'command_set_value_invalid' => '⚠️ Некорректное значение: без переносов строк и не длиннее 256 символов.',
    'command_set_org_link_invalid' => '⚠️ Пожалуйста, укажите корректную ссылку, начинающуюся с http:// или https://.',
    'command_set_value_unchanged' => 'ℹ️ :type уже установлен(а) — значение не изменилось.',
    'command_set_value_saved' => '✅ :type обновлён(а). Доверенные пользователи уведомлены.',
    'command_set_org_link_saved' => '✅ Ссылка на орг. информацию обновлена.',
    'command_set_value_error' => '❌ Не удалось сохранить значение. Попробуйте ещё раз.',

    // Регистрация пользователя
    'registration' => [
        'welcome' => "Добрый день!
Напишите пожалуйста свои ФИО полностью

Телефон по которому с Вами можно созвониться (в международном формате, например: +375XXXXXXXXX)

Напишите пожалуйста Ваш эмейл, он понадобится для доступа к графику",
        'ask_full_name' => 'Напишите пожалуйста свои ФИО полностью',
        'ask_phone' => 'Напишите пожалуйста телефон по которому с Вами можно созвониться (в международном формате, например: +375XXXXXXXXX)',
        'ask_email' => 'Напишите пожалуйста Ваш эмейл, он понадобится для доступа к графику',
        'completed' => '✅ Регистрация завершена! Все данные сохранены.',
        'received_message_first' => 'Мы получили ваше сообщение. Прежде чем продолжить, пожалуйста, пройдите короткую регистрацию — после неё вернёмся к вашему вопросу.',
        
        'my_data' => [
            'header' => '📋 Ваши данные:',
            'full_name' => 'ФИО',
            'phone' => 'Телефон',
            'email' => 'Email',
            'not_provided' => 'не указано',
        ],
        
        'edit_menu' => [
            'instructions' => 'Выберите поле для редактирования:',
            'edit_full_name' => '✏️ Редактировать ФИО',
            'edit_phone' => '✏️ Редактировать телефон',
            'edit_email' => '✏️ Редактировать email',
            'cancel' => '❌ Отменить',
        ],
        
        'edit' => [
            'ask_full_name' => 'Введите новое ФИО:',
            'ask_phone' => 'Введите новый номер телефона:',
            'ask_email' => 'Введите новый email:',
            'current_value' => 'Текущее значение <b>:field</b>: <code>:value</code>',
            'cancel_hint' => 'Для отмены используйте команду /cancel',
            'cancelled' => '❌ Редактирование отменено.',
            'full_name_saved' => '✅ ФИО успешно обновлено!',
            'phone_saved' => '✅ Номер телефона успешно обновлен!',
            'email_saved' => '✅ Email успешно обновлен!',
        ],
        
        'validation' => [
            'full_name_required' => '❌ Пожалуйста, введите ваше ФИО.',
            'full_name_too_short' => '❌ ФИО должно содержать минимум :min символов.',
            'full_name_truncated' => '⚠️ ФИО было обрезано до максимальной длины.',
            'phone_required' => '❌ Пожалуйста, введите номер телефона.',
            'phone_invalid' => '❌ Неверный формат номера телефона. Пожалуйста, введите номер в международном формате, например: +375XXXXXXXXX, +7XXXXXXXXXX, +1XXXXXXXXXX.',
            'email_required' => '❌ Пожалуйста, введите email.',
            'email_invalid' => '❌ Неверный формат email. Пожалуйста, введите корректный email адрес.',
            'text_required' => '❌ Пожалуйста, отправьте ответ текстом (не фото/стикером/голосовым).',
        ],
        
        'error' => [
            'save_failed' => '❌ Произошла ошибка при сохранении данных. Пожалуйста, попробуйте позже.',
        ],
    ],

];
