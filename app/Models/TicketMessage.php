<?php

namespace App\Models;

use App\Observers\TicketMessageObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;

#[ObservedBy([TicketMessageObserver::class])]
class TicketMessage extends Model implements Auditable
{
    use HasFactory, Traits\Auditable, Traits\GuardsMigrationWrites;

    protected $fillable = [
        'ticket_id',
        'user_id',
        'message',
        'ticket_mail_log_id',
    ];

    protected $casts = ['legacy_author' => 'array'];

    public function getAuthorNameAttribute(): string
    {
        return $this->legacy_author['name'] ?? $this->user?->name ?? 'Former user';
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function attachments()
    {
        return $this->hasMany(TicketMessageAttachment::class);
    }

    public function ticketMailLog()
    {
        return $this->belongsTo(TicketMailLog::class);
    }
}
