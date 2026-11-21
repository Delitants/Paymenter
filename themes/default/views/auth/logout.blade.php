<form method="POST" action="{{ route('logout') }}">
    @csrf
    <button type="submit" class="flex flex-row items-center p-3 gap-2 text-sm font-semibold text-error/80 hover:text-error bg-background-secondary hover:bg-background-secondary/80">
        {{ __('auth.logout') }}
    </button>
</form>
