<?php

namespace App\Support\ChannelTypes\Contracts;

use App\Models\NotificationChannel;

/**
 * A channel that sends through the subscriber's own provider account, bot or webhook (bring
 * your own): the phone types and the messaging types. Its credentials live in the encrypted
 * `config`, it gets nothing but its confirmation code until it is confirmed, and every text it
 * shows about a send is fixed, never built from a provider or Apprise response.
 */
interface ByoChannelType extends ChannelType
{
    /**
     * The fixed text recorded when the provider does not accept a send. Never built from
     * a provider or Apprise response.
     */
    public function failureMessage(): string;

    /**
     * What the wizard says after a code was sent to the channel's destination.
     */
    public function confirmationHint(NotificationChannel $channel): string;

    /**
     * What the wizard says when the code could not be sent: usually to check the
     * credentials and the destination, not that the service is unavailable.
     */
    public function codeNotSentHint(): string;
}
