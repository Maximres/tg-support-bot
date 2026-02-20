<?php

namespace App\Console\Commands;

use App\Actions\Telegram\SetBotCommands;
use Illuminate\Console\Command;

class TelegramSetCommands extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'telegram:set-commands {--check : Проверить текущие установленные команды}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Устанавливает команды бота для клиентов и администраторов';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $setBotCommands = new SetBotCommands();

        if ($this->option('check')) {
            return $this->checkCommands($setBotCommands);
        }

        $this->info('Установка команд бота...');
        $this->newLine();

        // Установка команд для клиентов
        $this->info('Установка команд для клиентов (private chats)...');
        $privateResult = $setBotCommands->setPrivateChatCommands();

        if ($privateResult) {
            $this->info('✅ Команды для клиентов установлены успешно');
        } else {
            $this->error('❌ Ошибка установки команд для клиентов');
        }

        $this->newLine();

        // Установка команд для администраторов
        $this->info('Установка команд для администраторов (group chats)...');
        $groupResult = $setBotCommands->setGroupChatCommands();

        if ($groupResult) {
            $this->info('✅ Команды для администраторов установлены успешно');
        } else {
            $this->error('❌ Ошибка установки команд для администраторов');
        }

        $this->newLine();

        if ($privateResult && $groupResult) {
            $this->info('✅ Все команды установлены успешно!');
            return Command::SUCCESS;
        } else {
            $this->error('❌ Произошли ошибки при установке команд');
            return Command::FAILURE;
        }
    }

    /**
     * Проверить текущие установленные команды
     *
     * @param SetBotCommands $setBotCommands
     *
     * @return int
     */
    private function checkCommands(SetBotCommands $setBotCommands): int
    {
        $this->info('Проверка текущих команд бота...');
        $this->newLine();

        // Проверка команд для клиентов
        $this->info('Команды для клиентов (private chats):');
        $privateCommands = $setBotCommands->getCommands(['type' => 'all_private_chats']);

        if ($privateCommands !== null && !empty($privateCommands)) {
            $this->table(
                ['Команда', 'Описание'],
                array_map(function ($cmd) {
                    return [$cmd['command'], $cmd['description']];
                }, $privateCommands)
            );
        } else {
            $this->warn('Команды для клиентов не установлены');
        }

        $this->newLine();

        // Проверка команд для администраторов
        $this->info('Команды для администраторов (group chats):');
        $groupCommands = $setBotCommands->getCommands(['type' => 'all_chat_administrators']);

        if ($groupCommands !== null && !empty($groupCommands)) {
            $this->table(
                ['Команда', 'Описание'],
                array_map(function ($cmd) {
                    return [$cmd['command'], $cmd['description']];
                }, $groupCommands)
            );
        } else {
            $this->warn('Команды для администраторов не установлены');
        }

        return Command::SUCCESS;
    }
}

