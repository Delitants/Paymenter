<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Models\TicketMailLog;
use App\Models\TicketMessage;
use App\Services\BillmanagerMigration\MigrationHold;
use App\Services\BillmanagerMigration\TicketMailReference;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\MessageInterface;
use EmailReplyParser\EmailReplyParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class FetchEmails extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fetch-emails';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import ticket emails using IMAP';

    public function handle(): int
    {
        if (!config('settings.ticket_mail_piping', false)) {
            $this->info('Email piping is not enabled. Skipping email fetch.');

            return self::SUCCESS;
        }
        Config::set('audit.console', true);
        $folder = config('settings.ticket_mail_folder');
        $dedicated = $folder !== null && $folder !== '';
        $missingMessageId = false;
        $mailbox = null;

        try {
            $mailbox = $this->mailbox();
            if ($dedicated) {
                if (!is_string($folder) || strlen($folder) > 255 || preg_match('/[\x00-\x1f\x7f]/', $folder)) {
                    throw new RuntimeException('Invalid configured ticket email folder.');
                }
                $source = $mailbox->folders()->find($folder);
                if ($source === null || $source->path() !== $folder) {
                    throw new RuntimeException('The configured ticket email folder was not found.');
                }
                $query = $source->messages()->unseen()->leaveUnread();
            } else {
                $query = $mailbox->inbox()->messages()->since(now()->subDays(1));
            }
            $archive = $dedicated ? new TicketMailReference : null;

            foreach ($query->withHeaders()->withBody()->get() as $email) {
                $messageId = $email->messageId();
                if ($dedicated && !$messageId) {
                    $missingMessageId = true;
                    continue;
                }

                if (!TicketMailLog::where('message_id', $messageId)->exists()) {
                    $ticket = $archive ? $this->dedicatedTicket($email, $archive) : $this->inboxTicket($email);
                    $sender = $email->from()?->email();
                    if (!$ticket || !$sender || $sender !== $ticket->user?->email ||
                        ($dedicated && MigrationHold::isHeld($ticket))) {
                        $this->logMessage($email, 'unprocessed');
                    } else {
                        $this->importReply($email, $ticket);
                    }
                }
                if ($dedicated) {
                    $email->markSeen();
                }
            }
        } catch (Throwable $error) {
            if ($dedicated) {
                $this->error('Ticket mail could not be imported from the configured folder.');
                \Log::warning('Configured ticket mail import failed', ['exception_type' => $error::class]);

                return self::FAILURE;
            }
            \Log::error('FetchEmails failed: ' . $error->getMessage());
        } finally {
            $mailbox?->disconnect();
        }

        if ($missingMessageId) {
            $this->warn('Ticket mail without a Message-ID was left unread.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function mailbox(): MailboxInterface
    {
        return new Mailbox([
            'port' => config('settings.ticket_mail_port'),
            'username' => config('settings.ticket_mail_email'),
            'password' => config('settings.ticket_mail_password'),
            'host' => config('settings.ticket_mail_host'),
        ]);
    }

    private function inboxTicket(MessageInterface $email): ?Ticket
    {
        $replyTo = $email->inReplyTo();
        if (!isset($replyTo[0]) || !preg_match('/^(\d+)@/', $replyTo[0], $matches)) {
            return null;
        }

        return TicketMessage::find($matches[1])?->ticket;
    }

    private function dedicatedTicket(MessageInterface $email, TicketMailReference $archive): ?Ticket
    {
        $replyTo = $email->inReplyTo();
        $ticketIds = $archive->matchingTickets($replyTo);
        if ($ticketIds === null) {
            return null;
        }
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        foreach ($replyTo as $reference) {
            if (!is_string($reference)) {
                continue;
            }
            $reference = trim($reference, " \r\n\t");
            if (str_starts_with($reference, '<') && str_ends_with($reference, '>')) {
                $reference = substr($reference, 1, -1);
            }
            if (is_string($host) &&
                preg_match('/\A([1-9][0-9]*)@([A-Za-z0-9.-]+)\z/D', $reference, $match) &&
                strcasecmp($match[2], $host) === 0) {
                $message = TicketMessage::find($match[1]);
                if ($message && (string) $message->id === $match[1]) {
                    $ticketIds[] = (int) $message->ticket_id;
                }
            }
        }
        if (!$ticketIds) {
            // Native References headers can contain ticket IDs. Only an archived
            // Message-ID provides evidence for the legacy fallback.
            $references = array_map(static fn ($part) => $part->getValue(), $email->header('References')?->getParts() ?? []);
            $ticketIds = $archive->matchingTickets($references);
            if ($ticketIds === null) {
                return null;
            }
        }
        $ticketIds = array_values(array_unique($ticketIds));

        return count($ticketIds) === 1 ? Ticket::find($ticketIds[0]) : null;
    }

    private function importReply(MessageInterface $email, Ticket $ticket): void
    {
        $files = [];
        try {
            DB::transaction(function () use ($email, $ticket, &$files): void {
                $log = $this->logMessage($email, 'processed');
                $message = $ticket->messages()->create([
                    'message' => EmailReplyParser::parseReply($email->text()),
                    'user_id' => $ticket->user_id,
                    'ticket_mail_log_id' => $log->id,
                ]);
                foreach ($email->attachments() as $attachment) {
                    $name = Str::ulid() . '.' . pathinfo($attachment->filename(), PATHINFO_EXTENSION);
                    $path = 'tickets/uploads/' . $name;
                    $file = storage_path('app/' . $path);
                    $files[] = $file;
                    $attachment->save($file);
                    $message->attachments()->create([
                        'path' => $path,
                        'filename' => $attachment->filename(),
                        'mime_type' => File::mimeType($file),
                        'filesize' => File::size($file),
                    ]);
                }
            });
        } catch (Throwable $error) {
            File::delete($files);
            throw $error;
        }
    }

    private function logMessage(MessageInterface $email, string $status): TicketMailLog
    {
        return TicketMailLog::create([
            'message_id' => $email->messageId(),
            'subject' => $email->subject(),
            'from' => $email->from()?->email() ?? '',
            'to' => ($email->to()[0] ?? null)?->email() ?? config('settings.ticket_mail_email'),
            'body' => $email->text(),
            'status' => $status,
        ]);
    }
}
