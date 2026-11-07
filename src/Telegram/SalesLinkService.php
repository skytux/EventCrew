<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\DoorLinkSender;
use EventCrew\Support\Language;
use EventCrew\Support\SalesDeskGate;

/**
 * 💳 Sales desk (a button under /door): a fresh login link for the sales desk (the door page), sent to the
 * asker's DM.
 *
 * Organizers get one for the next event night at any time. People on the
 * night's task of a sales-desk role get one from a few minutes before their
 * task starts, once they are marked arrived, until the event ends -
 * SalesDeskGate's rule. Anyone else is told plainly why not. It is how someone
 * gets a second link when the automatic one was opened on the wrong phone, or
 * never came. The link goes only to the DM, never into the group, since
 * whoever opens it first holds the session.
 */
final class SalesLinkService
{
    use GroupBreadcrumb;

    public function __construct(
        private readonly SalesDeskGate $gate,
        private readonly PersonRepository $people,
        private readonly DoorLinkSender $sender,
        private readonly TelegramClient $telegram
    ) {
    }

    public function onSalesCommand(int $telegramUserId, int $chatId, bool $isPrivate = true): void
    {
        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person) {
            // Not known, so the bot may not be able to DM them: the refusal
            // goes back to wherever they asked, in English in the group.
            $refusal = static fn (): string => SalesDeskGate::message(SalesDeskGate::NOT_CREW);
            $this->telegram->sendMessage(
                $chatId,
                $isPrivate ? $refusal() : Language::run(Language::ENGLISH, $refusal)
            );

            return;
        }

        $date = $this->gate->nextNight();
        $code = $this->gate->check($person, $date);

        // A private chat's id is the user's id, so the DM goes to the asker
        // even when the command came from the group. A refusal goes there too,
        // with a breadcrumb in the group.
        if (SalesDeskGate::OK !== $code || ! $this->sender->send($person, $date, false, $telegramUserId)) {
            $this->telegram->sendMessage(
                $telegramUserId,
                SalesDeskGate::message(SalesDeskGate::OK === $code ? SalesDeskGate::CLOSED : $code),
                MenuButtons::back(MenuButtons::DOOR)
            );
            $this->sentDmNote($chatId, $isPrivate);

            return;
        }

        $this->sentDmNote($chatId, $isPrivate);
    }
}
