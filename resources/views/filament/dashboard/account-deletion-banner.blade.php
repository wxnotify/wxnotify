<div class="mb-4 rounded-lg bg-danger-50 p-4 text-sm text-danger-700 ring-1 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400 dark:ring-danger-400/30" role="alert">
    Your account is scheduled for deletion on {{ auth()->user()->deletionDate()->format('F j, Y') }}, and your alerts are paused.
    <a href="{{ filament()->getProfileUrl() }}#delete-account" class="font-semibold underline">Cancel deletion</a>
</div>
