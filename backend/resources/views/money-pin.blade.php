<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Confirm your PIN
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-md mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm sm:rounded-lg p-6">

                <p class="text-sm text-gray-600 mb-4">
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
                           class="underline text-sm text-gray-600 hover:text-gray-900 mr-4">
                            Cancel
                        </a>
                        <x-primary-button>{{ __('Unlock') }}</x-primary-button>
                    </div>
                </form>

            </div>
        </div>
    </div>
</x-app-layout>
