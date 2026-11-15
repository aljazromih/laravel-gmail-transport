<?php

namespace AljazRomih\GmailTransport;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * For the model that connects a Gmail account, usually User.
 *
 * @mixin Model
 */
trait HasGmailAccount
{
    /**
     * A deleted owner would keep its address taken and its grant alive at Google. A soft-deleted
     * owner can come back, so only deleting it for good disconnects.
     */
    public static function bootHasGmailAccount(): void
    {
        $event = in_array(SoftDeletes::class, class_uses_recursive(static::class), true) ? 'forceDeleting' : 'deleting';

        static::registerModelEvent($event, function (Model $owner): void {
            GmailAccount::query()
                ->where('owner_type', $owner->getMorphClass())
                ->where('owner_id', $owner->getKey())
                ->first()
                ?->disconnect();
        });
    }

    /**
     * @return MorphOne<GmailAccount, $this>
     */
    public function gmailAccount(): MorphOne
    {
        return $this->morphOne(GmailAccount::class, 'owner');
    }
}
