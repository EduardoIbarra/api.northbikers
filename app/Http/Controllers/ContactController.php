<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Contact; // Import the Contact model

class ContactController extends Controller
{
    public function store(Request $request)
    {
        // Validate the request data
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'message' => 'required|string',
            'type' => 'required|string|max:255',
        ]);

        // Use the Contact model to insert the data into the contacts table
        Contact::create($validatedData);

        // Redirect or return a response (adjust as needed)
        return response()->json(['message' => 'Contact saved successfully!'], 201);
    }
}
