<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:100'],
        ]);

        $paymentMethod = $request->user()->paymentMethods()->create($validated);

        return response()->json($paymentMethod, 201);
    }
}
