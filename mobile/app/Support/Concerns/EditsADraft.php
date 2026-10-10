<?php

namespace App\Support\Concerns;

use App\Services\SignOut;

/**
 * The leaving and confirming half of an editor that holds a draft until Save: the delete
 * confirmation, the "discard your changes?" prompt on the back gesture, and the local sign-out
 * when the server refuses the token. The editor declares `$ready`, `$draft` and `$original`.
 */
trait EditsADraft
{
    public bool $confirmingDelete = false;

    public bool $confirmingDiscard = false;

    /**
     * Whether the draft holds edits the subscriber has not saved.
     */
    abstract public function isDirty(): bool;

    public function askDelete(): void
    {
        $this->confirmingDelete = true;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDelete = false;
    }

    /**
     * The system back gesture: leave straight away unless there are unsaved edits.
     */
    public function onBackPressed(): void
    {
        if ($this->ready && $this->isDirty()) {
            $this->confirmingDiscard = true;

            return;
        }

        $this->back();
    }

    public function discard(): void
    {
        $this->original = $this->draft;
        $this->back();
    }

    public function keepEditing(): void
    {
        $this->confirmingDiscard = false;
    }

    private function signOutLocally(): void
    {
        app(SignOut::class)->clearLocal();
        $this->replace('/sign-in');
    }
}
