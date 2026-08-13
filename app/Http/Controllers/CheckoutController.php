<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\BasketServiceContract;
use App\Contracts\BookingCreationServiceContract;
use App\Contracts\PaymentAdapterResolverContract;
use App\Contracts\PaymentMethodServiceContract;
use App\DataTransferObjects\BookingRequest;
use App\DataTransferObjects\CustomerDetails;
use App\Exceptions\BookingNotPossibleException;
use App\Exceptions\PaymentMethodNotAvailableException;
use App\Exceptions\VehicleNotAvailableException;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\Vehicle;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Spec §1.3: the first and only place a customer is asked who they are.
 *
 * THREE THINGS THIS PAGE MUST NOT DO
 *
 * It must not re-price. The basket froze a quote and that is what the customer
 * pays — §1.2. Recomputing here would honour a rate change mid-checkout.
 *
 * It must not reveal whether an email already has an account. §1.4, and it is a
 * security requirement rather than a courtesy: a checkout that renders
 * differently for a known address lets anybody enumerate the customer list by
 * starting checkouts. `CustomerResolver` decides linking server-side and this
 * controller never reads its answer to change what is displayed.
 *
 * It must not trust the submitted payment method. §14.2 requires server-side
 * refusal; `BookingCreationService` asks `PaymentMethodService` regardless of
 * what the form offered.
 */
final class CheckoutController extends Controller
{
    public function __construct(
        private readonly BasketServiceContract $baskets,
        private readonly PaymentMethodServiceContract $methods,
        private readonly BookingCreationServiceContract $bookings,
        private readonly PaymentAdapterResolverContract $adapters,
    ) {}

    public function show(): View|RedirectResponse
    {
        $basket = $this->baskets->touch();

        if ($basket === null) {
            return $this->noBasket();
        }

        $vehicle = Vehicle::query()->with('vehicleClass')->find($basket->vehicleId);
        $branch = Branch::query()->find($basket->pickupBranchId);

        if ($vehicle === null || $branch === null) {
            return $this->noBasket();
        }

        return view('checkout', [
            'basket' => $basket,
            'quote' => $basket->quote,
            'vehicle' => $vehicle,
            'class' => $vehicle->vehicleClass,
            'branch' => $branch,
            'range' => $basket->range,
            // Spec §8.2 lives in here: with pickup imminent, only methods
            // settled at the branch survive.
            'methods' => $this->methods->selectableFor($basket->range->start),
            'expiresAt' => $this->baskets->expiresAt(),
            'termsVersion' => (string) config('carhire.terms_version', '2026-08-01'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $basket = $this->baskets->touch();

        if ($basket === null) {
            return $this->noBasket();
        }

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'payment_method' => ['required', 'string'],
            'pay_in_full' => ['nullable', 'boolean'],
            'terms' => ['accepted'],
        ], [
            'terms.accepted' => 'Please accept the terms and conditions to continue.',
        ], [
            // The customer reads a label, not a column name. "The phone field
            // is required" under a field labelled "Mobile number" makes them
            // hunt for a field that is not on the page.
            'full_name' => 'full name',
            'phone' => 'mobile number',
        ]);

        $vehicle = Vehicle::query()->with('vehicleClass')->findOrFail($basket->vehicleId);
        $branch = Branch::query()->findOrFail($basket->pickupBranchId);

        try {
            $result = $this->bookings->create(new BookingRequest(
                vehicle: $vehicle,
                range: $basket->range,
                pickupBranch: $branch,
                dropoffBranch: $branch,
                customer: new CustomerDetails(
                    fullName: (string) $data['full_name'],
                    email: (string) $data['email'],
                    phone: (string) $data['phone'],
                ),
                paymentMethodCode: (string) $data['payment_method'],
                payInFull: (bool) ($data['pay_in_full'] ?? false),
                termsVersion: (string) config('carhire.terms_version', '2026-08-01'),
                quoteOptions: $basket->options,
                // Always null here. Linking to an existing customer requires
                // proven identity, and a guest checkout proves nothing — §1.4.
                verifiedCustomer: null,
            ));
        } catch (VehicleNotAvailableException) {
            // Somebody took it while this customer was typing. Their money has
            // not moved and nothing partial survives the transaction.
            $this->baskets->forget();

            return redirect()
                ->route('search', [
                    'branch' => $branch->getKey(),
                    'pickup' => $basket->range->start->setTimezone(config('carhire.display_timezone'))->format('Y-m-d\TH:i'),
                    'dropoff' => $basket->range->end->setTimezone(config('carhire.display_timezone'))->format('Y-m-d\TH:i'),
                ])
                ->with('notice', 'That vehicle was taken while you were filling in your details. Nothing has been charged. Here is what else is free for your dates.');
        } catch (PaymentMethodNotAvailableException $e) {
            // Recoverable on this page: choose another method.
            throw ValidationException::withMessages(['payment_method' => $e->getMessage()]);
        } catch (BookingNotPossibleException $e) {
            throw ValidationException::withMessages(['full_name' => $e->getMessage()]);
        }

        $this->baskets->forget();

        return redirect()->route('booking.confirmation', ['reference' => $result->booking->reference]);
    }

    /**
     * The screen a customer stares at while deciding whether to send money.
     *
     * Reachable by reference and without an account, because that is how
     * somebody returns to their payment instructions from an email. The
     * reference is unguessable enough for that and carries nothing sensitive.
     */
    public function confirmation(string $reference): View
    {
        $booking = Booking::query()
            ->with(['vehicleClass', 'pickupBranch', 'customer'])
            ->where('reference', $reference)
            ->firstOrFail();

        $payment = $booking->payments()->orderBy('id')->firstOrFail();

        $method = PaymentMethod::query()
            ->where('code', $payment->payment_method_code->value)
            ->first();

        return view('confirmation', [
            'booking' => $booking,
            'payment' => $payment,
            'method' => $method,
            'instructions' => $method === null ? '' : $this->adapters
                ->for($payment->payment_method_code)
                ->instructionsFor($payment, $method, $booking->payment_deadline_at),
        ]);
    }

    private function noBasket(): RedirectResponse
    {
        return redirect()
            ->route('home')
            ->with('notice', 'Your basket has expired. Prices are held for a limited time — please search again.');
    }
}
