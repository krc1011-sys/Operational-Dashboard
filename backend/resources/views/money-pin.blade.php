<x-operon-page title="Confirm your PIN">
    

    <div class="op-legacy">
        <div style="display:flex;flex-direction:column;gap:16px">
            <div class="panel">

                <p class="text-sm mb-4" style="color:var(--muted)">
                    This screen shows cost, price or margin figures. Enter your PIN to unlock
                    them for the next {{ config('operon.money_pin_timeout') }} minutes.
                </p>

                <form method="POST" action="{{ route('money-pin.store') }}">
                    @csrf

                    <x-input-label for="pin" :value="__('PIN')" />
                    <x-text-input id="pin" name="pin" type="password" class="mt-1 block w-full"
                                  required autofocus autocomplete="off" inputmode="numeric" />
                    <x-input-error :messages="$errors->get('pin')" class="mt-2" />

                    <div class="flex items-center justify-end mt-4">
                        <a href="{{ route('dashboard') }}"
                           class="underline text-sm hover:text-gray-900 mr-4" style="color:var(--muted)">
                            Cancel
                        </a>
                        <x-primary-button>{{ __('Unlock') }}</x-primary-button>
                    </div>
                </form>

            </div>
        </div>
    </div>
</x-operon-page>
