<x-guest-layout>
    <div class="min-h-screen bg-gray-100 py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow sm:rounded-lg p-8 prose prose-sm max-w-none">
                <h1 class="text-2xl font-bold mb-4">Privacy Policy</h1>

                <p class="mb-4 text-sm text-gray-600">
                    Last updated: {{ now()->format('Y-m-d') }}. This page summarizes how the Employee
                    Management platform handles personal data. Your organization's own policies and
                    employment agreements take precedence where they are stricter.
                </p>

                <h2 class="text-lg font-semibold mt-6 mb-2">What we collect</h2>
                <ul class="list-disc pl-5 mb-4 text-sm text-gray-700 space-y-1">
                    <li>Account identity: name, email address, password (stored only as a bcrypt hash).</li>
                    <li>Employment data your administrator maintains: employee code, department, designation, contact details, work schedule, attendance punches, leave requests, and — where applicable — salary and payslip records.</li>
                    <li><strong>IP address and derived location on login</strong>: every login attempt (success or failure) records the client IP address and a best-effort, server-side geolocation (country, and where resolvable, division/region and city). This is approximate, is stored only for security monitoring and audit purposes, and is never used as the sole basis for blocking access.</li>
                    <li>Device / user-agent string on login attempts.</li>
                    <li>Audit trail entries for administrative actions (who changed what, when, from which IP).</li>
                </ul>

                <h2 class="text-lg font-semibold mt-6 mb-2">How location is resolved</h2>
                <p class="mb-4 text-sm text-gray-700">
                    Location is resolved on the server from the IP address using a GeoIP database or
                    provider (for example MaxMind GeoLite2). It is never resolved in your browser.
                    VPNs, mobile carriers and proxies can make this approximate location wrong; treat
                    it as a security signal, not a precise position.
                </p>

                <h2 class="text-lg font-semibold mt-6 mb-2">Retention</h2>
                <ul class="list-disc pl-5 mb-4 text-sm text-gray-700 space-y-1">
                    <li>Login history is retained for up to 180 days by default (your organization may configure a shorter window).</li>
                    <li>Audit logs are retained for up to 365 days by default (your organization may configure a shorter window).</li>
                    <li>A daily <code>privacy:prune</code> job deletes rows older than the configured windows.</li>
                    <li>Salary and payslip data follow your organization's payroll retention obligations.</li>
                </ul>

                <h2 class="text-lg font-semibold mt-6 mb-2">Your controls</h2>
                <ul class="list-disc pl-5 mb-4 text-sm text-gray-700 space-y-1">
                    <li><strong>Data export</strong>: you can download a JSON copy of your own account data from the Profile page ("Download my data"). Administrators can export any org member's data from the Users page.</li>
                    <li><strong>Account deactivation / removal</strong>: self-service deletion is disabled. Deactivation and deletion are administrator-controlled workflows (Users page). Contact your company admin or file a support ticket.</li>
                    <li><strong>Two-factor authentication</strong>: you can enable TOTP-based MFA and regenerate recovery codes from the Profile page.</li>
                </ul>

                <h2 class="text-lg font-semibold mt-6 mb-2">Access & security</h2>
                <ul class="list-disc pl-5 mb-4 text-sm text-gray-700 space-y-1">
                    <li>Data is encrypted in transit via TLS and salary secrets / MFA secrets / SSO client secrets are encrypted at rest.</li>
                    <li>Access is least-privilege: role-based permissions gate every page and API route; salary data never appears in public APIs, logs, search indexes or analytics endpoints.</li>
                    <li>All administrative actions are written to an audit log with actor, IP and old/new values.</li>
                </ul>

                <h2 class="text-lg font-semibold mt-6 mb-2">Questions</h2>
                <p class="text-sm text-gray-700">
                    Contact your organization's administrator through the in-app Support page for
                    access, export or deletion requests.
                </p>

                <p class="mt-8">
                    <a href="{{ url('/') }}" class="text-indigo-600 hover:underline">&larr; Back to the app</a>
                </p>
            </div>
        </div>
    </div>
</x-guest-layout>
