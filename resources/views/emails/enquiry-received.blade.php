<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: sans-serif; color: #1f2937;">
    <p>New <strong>{{ $enquiry->type->value }}</strong> enquiry, reference <strong>{{ $enquiry->reference }}</strong>.</p>

    <table cellpadding="4" style="border-collapse: collapse;">
        <tr><td><strong>Name</strong></td><td>{{ $enquiry->name }}</td></tr>
        <tr><td><strong>Email</strong></td><td>{{ $enquiry->email }}</td></tr>
        @if ($enquiry->phone)<tr><td><strong>Phone</strong></td><td>{{ $enquiry->phone }}</td></tr>@endif
        @if ($enquiry->company)<tr><td><strong>Company</strong></td><td>{{ $enquiry->company }}</td></tr>@endif
        @if ($enquiry->fleet_size)<tr><td><strong>Fleet size</strong></td><td>{{ $enquiry->fleet_size }}</td></tr>@endif
        @if ($enquiry->tyre_size)<tr><td><strong>Tyre size</strong></td><td>{{ $enquiry->tyre_size }}</td></tr>@endif
        @if ($enquiry->rego)<tr><td><strong>Rego</strong></td><td>{{ $enquiry->rego }} ({{ $enquiry->rego_state }})</td></tr>@endif
        @if ($enquiry->suburb || $enquiry->postcode)<tr><td><strong>Location</strong></td><td>{{ trim($enquiry->suburb.' '.$enquiry->postcode) }}</td></tr>@endif
    </table>

    @if ($enquiry->message)
        <p><strong>Message</strong></p>
        <p style="white-space: pre-wrap;">{{ $enquiry->message }}</p>
    @endif

    <p>Reply to this email to answer the customer directly.</p>
</body>
</html>
