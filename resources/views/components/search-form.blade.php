@props([
    'branches',
    'branchId' => null,
    'pickupAt' => null,
    'dropoffAt' => null,
    'compact' => false,
])

@php
    // Sensible defaults so a visitor can press Search without thinking: pickup
    // tomorrow morning, back three days later. Spec §1.1 wants a quote with no
    // friction, and an empty date field is friction.
    $defaultPickup = $pickupAt ?? now(config('carhire.display_timezone'))->addDay()->setTime(9, 0)->format('Y-m-d\TH:i');
    $defaultDropoff = $dropoffAt ?? now(config('carhire.display_timezone'))->addDays(4)->setTime(9, 0)->format('Y-m-d\TH:i');
@endphp

<form method="GET"
      action="{{ route('search') }}"
      class="rounded-2xl bg-white p-3 shadow-xl shadow-brand-950/20 sm:p-4">

    <div @class([
        'grid gap-3',
        'sm:grid-cols-2 lg:grid-cols-[1.2fr_1fr_1fr_auto]' => ! $compact,
        'sm:grid-cols-2 lg:grid-cols-4' => $compact,
    ])>
        <div>
            <label for="branch" class="block text-xs font-semibold uppercase tracking-wide text-ink-500">
                Collecting from
            </label>
            <select id="branch"
                    name="branch"
                    @if ($errors->has('branch')) autofocus @endif
                    aria-describedby="{{ $errors->has('branch') ? 'branch_error' : '' }}"
                    @class([
                        'mt-1.5 w-full rounded-xl bg-white py-3 text-base text-ink-900 shadow-sm focus:ring-2 focus:ring-brand-600/30',
                        'border-ink-300 focus:border-brand-600' => ! $errors->has('branch'),
                        'border-danger-600 focus:border-danger-600' => $errors->has('branch'),
                    ])>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}" @selected((int) old('branch', $branchId) === (int) $branch->id)>
                        {{ $branch->name }}
                    </option>
                @endforeach
            </select>
            @error('branch')
                <p id="branch_error" class="mt-1.5 text-sm text-danger-600" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="pickup" class="block text-xs font-semibold uppercase tracking-wide text-ink-500">
                Pick-up
            </label>
            {{-- datetime-local gives phones their native date and time pickers,
                 which beats anything hand-built on a small screen. --}}
            <input id="pickup"
                   type="datetime-local"
                   name="pickup"
                   value="{{ old('pickup', $defaultPickup) }}"
                   required
                   @if ($errors->has('pickup') && ! $errors->has('branch')) autofocus @endif
                   aria-describedby="{{ $errors->has('pickup') ? 'pickup_error' : '' }}"
                   @class([
                       'mt-1.5 w-full rounded-xl py-3 text-base text-ink-900 shadow-sm focus:ring-2 focus:ring-brand-600/30',
                       'border-ink-300 focus:border-brand-600' => ! $errors->has('pickup'),
                       'border-danger-600 focus:border-danger-600' => $errors->has('pickup'),
                   ])>
            @error('pickup')
                <p id="pickup_error" class="mt-1.5 text-sm text-danger-600" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="dropoff" class="block text-xs font-semibold uppercase tracking-wide text-ink-500">
                Return
            </label>
            <input id="dropoff"
                   type="datetime-local"
                   name="dropoff"
                   value="{{ old('dropoff', $defaultDropoff) }}"
                   required
                   @if ($errors->has('dropoff') && ! $errors->hasAny(['branch', 'pickup'])) autofocus @endif
                   aria-describedby="{{ $errors->has('dropoff') ? 'dropoff_error' : '' }}"
                   @class([
                       'mt-1.5 w-full rounded-xl py-3 text-base text-ink-900 shadow-sm focus:ring-2 focus:ring-brand-600/30',
                       'border-ink-300 focus:border-brand-600' => ! $errors->has('dropoff'),
                       'border-danger-600 focus:border-danger-600' => $errors->has('dropoff'),
                   ])>
            @error('dropoff')
                <p id="dropoff_error" class="mt-1.5 text-sm text-danger-600" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-end">
            {{-- Full width on small screens: a primary action a thumb has to
                 aim for is a primary action people miss. --}}
            <button type="submit"
                    class="pressable w-full cursor-pointer rounded-xl bg-brand-600 px-6 py-3.5 font-display text-base font-semibold text-white shadow-sm [transition:transform_160ms_var(--ease-out-strong),background-color_160ms_ease] hover:bg-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700 lg:w-auto">
                Search
            </button>
        </div>
    </div>

    @error('dates')
        <p class="mt-3 flex items-start gap-1.5 text-sm text-danger-600" role="alert">
            <svg aria-hidden="true" class="mt-0.5 size-4 shrink-0" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v4a1 1 0 102 0V7zm-1 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
            </svg>
            {{ $message }}
        </p>
    @enderror
</form>
