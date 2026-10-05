<?php

namespace Database\Seeders;

use App\Enums\ContentPageType;
use App\Enums\PageStatus;
use App\Models\ContentPage;
use App\Models\Faq;
use App\Support\ContentPlaceholderResolver;
use App\Support\ContentTokens;
use Illuminate\Database\Seeder;

/**
 * Seeds the real launch copy for the four legally-required static pages
 * (Terms & Conditions, Privacy Policy, About Us, Contact) plus the initial
 * global FAQ set, through the existing Phase 6 CMS infrastructure
 * ({@see ContentPage}, {@see Faq}) — no new tables/columns, this is content
 * only.
 *
 * The page bodies are authored with bracketed template tokens (e.g.
 * `[SUPPORT_EMAIL]`) which are resolved from `config/business.php` by
 * {@see ContentPlaceholderResolver} before saving — a literal `[TOKEN]` is
 * never stored (asserted below). The legal copy is operational draft text
 * and still needs legal review by the owner.
 *
 * Idempotent — safe to re-run (`updateOrCreate` keyed on `type` + `slug` for
 * pages, and on `question` + `content_page_id` for the global FAQs; never a
 * bare `create`).
 */
class LaunchContentSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPages();
        $this->seedFaqs();
        $this->resolvePlaceholders();

        $this->command->info('Launch content seeded: 4 content pages, 5 global FAQs.');
    }

    /**
     * Resolves template tokens in everything this seeder owns, then fails
     * loudly if any `[UPPER_CASE]` token survives.
     */
    private function resolvePlaceholders(): void
    {
        ContentPage::query()->where('type', ContentPageType::Page)->whereIn('slug', ['terms-conditions', 'privacy-policy', 'about-us', 'contact'])->get()->each(function (ContentPage $page): void {
            $body = ContentPlaceholderResolver::resolve((string) $page->body);
            ContentTokens::assertNone([$body], "content page [{$page->slug}]");
            $page->forceFill(['body' => $body])->saveQuietly();
        });

        Faq::query()->whereNull('content_page_id')->get()->each(function (Faq $faq): void {
            $answer = ContentPlaceholderResolver::resolve((string) $faq->answer);
            ContentTokens::assertNone([$answer], "FAQ [{$faq->question}]");
            $faq->forceFill(['answer' => $answer])->saveQuietly();
        });
    }

    private function seedPages(): void
    {
        ContentPage::query()->updateOrCreate(
            ['type' => ContentPageType::Page, 'slug' => 'terms-conditions'],
            [
                'title' => 'Terms & Conditions',
                'status' => PageStatus::Published,
                'published_at' => now(),
                'body' => <<<'HTML'
<h2>1. Acceptance of these terms</h2>
<p>These terms govern every booking made through this website (the "Site") and every tyre fitting carried out by Tiro Mobile Tyres ("Tiro", "we", "us", "our"). By placing a booking you accept these terms in full. If you do not agree with any part of them, please do not use the Site — contact us and we will help you a different way.</p>

<h2>2. Our service</h2>
<p>Tiro supplies and fits tyres at a location you choose — your home, your workplace, or another address within our serviced area. We confirm your fitting appointment, the tyres you have selected, and the fitted price before you pay. Availability, stock levels, and serviceable suburbs are shown to you at the time of booking and may change without notice.</p>
<p>Our fitters are trained and equipped to fit, balance, and dispose of tyres safely and in accordance with applicable Australian road safety and workplace standards. A fitting may be declined on safety grounds — for example if a vehicle is unroadworthy in a way unrelated to the tyres being fitted, or if safe access to the vehicle isn't available at the booked location.</p>

<h2>3. Bookings, rescheduling &amp; cancellations</h2>
<p>A booking is confirmed once you receive a confirmation email or SMS. You can reschedule or cancel a booking from your account, or using the link in your confirmation message, up to [CANCELLATION_NOTICE_HOURS] hours before your appointment at no charge.</p>
<p>Cancellations made after that window, or a missed appointment where our fitter attends and cannot gain access to the vehicle or location, may incur a cancellation fee. Any applicable fee is shown to you before you confirm a late change, in line with our published cancellation policy.</p>
<p>Guest bookings (made without creating an account) can be managed using the secure link sent to you at the time of booking. This link is time-limited — if it has expired, please contact us or create an account with the same email address to view your booking history.</p>

<h2>4. Pricing &amp; payment</h2>
<p>All prices shown are in Australian dollars and include GST where applicable. The fitted price shown to you at checkout is the price you pay — it includes fitting, wheel balancing, and responsible disposal of your old tyres, with no undisclosed add-ons at the appointment.</p>
<p>Payment is processed securely by our payment provider at the time of booking. A fitting appointment is only confirmed once payment has been successfully processed. We do not store your full card details.</p>
<p>If we are unable to complete a fitting for reasons within our control (for example, incorrect stock or fitter unavailability), you will be offered a full refund or a rescheduled appointment at no additional cost.</p>

<h2>5. Price guarantee</h2>
<p>If you find an identical tyre, fitted, advertised at a genuinely lower total price by another Australian retailer before you book, we will match it — see our price guarantee terms in your account for the current eligibility criteria and how to submit a claim.</p>

<h2>6. Warranties</h2>
<p>Tyres are covered by the applicable manufacturer's warranty against defects in materials and workmanship. Our fitting work is covered by our own workmanship guarantee for [WORKMANSHIP_WARRANTY_PERIOD] from the date of fitting. Nothing in these terms excludes, restricts, or modifies any consumer guarantee, right, or remedy conferred on you under the Australian Consumer Law that cannot lawfully be excluded, restricted, or modified.</p>

<h2>7. Limitation of liability</h2>
<p>To the maximum extent permitted by law, and subject to the Australian Consumer Law guarantees referred to above, Tiro's liability for any loss or damage arising from our service is limited, at our option, to the resupply of the service or the cost of having the service resupplied. We are not liable for indirect or consequential loss.</p>

<h2>8. Complaints</h2>
<p>If something isn't right, please contact us at [SUPPORT_EMAIL] or [SUPPORT_PHONE] — we aim to acknowledge every complaint within two business days and resolve it as quickly as possible.</p>

<h2>9. Governing law</h2>
<p>These terms are governed by the law of [STATE/TERRITORY], Australia, and you submit to the non-exclusive jurisdiction of its courts.</p>

<p><em>Tiro Mobile Tyres — ABN [ABN PLACEHOLDER]. This document is a complete operational draft; the bracketed placeholders should be reviewed and confirmed (ideally with legal advice) before relying on it commercially.</em></p>
HTML,
            ],
        );

        ContentPage::query()->updateOrCreate(
            ['type' => ContentPageType::Page, 'slug' => 'privacy-policy'],
            [
                'title' => 'Privacy Policy',
                'status' => PageStatus::Published,
                'published_at' => now(),
                'body' => <<<'HTML'
<h2>1. Overview</h2>
<p>Tiro Mobile Tyres ("Tiro", "we", "us") is committed to protecting your privacy in accordance with the Privacy Act 1988 (Cth) and the Australian Privacy Principles (APPs). This policy explains what personal information we collect, how we use it, and your rights.</p>

<h2>2. Information we collect</h2>
<p>We collect information you provide directly — your name, email address, mobile number, vehicle details, service address, and payment details — when you register an account, request a quote, or make a booking. We also collect information automatically, such as your approximate location (with your permission) to check service availability in your area, and standard technical information (browser type, IP address) needed to operate the Site securely.</p>

<h2>3. How we use your information</h2>
<p>We use your information to: process and fulfil bookings; process payments; send booking confirmations, reminders, and service updates by email and SMS; respond to enquiries and price-match claims; and improve our service. We do not sell your personal information to third parties.</p>

<h2>4. Who we share information with</h2>
<p>We share information with trusted service providers strictly as needed to deliver our service: our payment processor, to process your payment securely; our SMS provider, to send booking reminders; and, where you leave a review, Google's Business Profile platform. Each provider is only given the information it needs to perform its function, and is contractually required to protect it.</p>

<h2>5. Data security</h2>
<p>We store your information on secure, access-controlled systems and use industry-standard encryption for data in transit. Payment card details are handled directly by our PCI-compliant payment processor — we never store your full card number.</p>

<h2>6. Cookies</h2>
<p>We use essential cookies to keep you signed in, remember your service location, and keep your cart contents between visits. We do not use third-party advertising or tracking cookies.</p>

<h2>7. Your rights</h2>
<p>You may request access to, correction of, or deletion of your personal information at any time by contacting us at [PRIVACY_EMAIL] or through your account settings. We will respond within a reasonable period, in line with our obligations under the Australian Privacy Principles.</p>

<h2>8. Data retention</h2>
<p>We retain booking and order records for as long as required by Australian tax and consumer-law record-keeping obligations, and delete or de-identify personal information once it is no longer needed for these purposes or for an active account.</p>

<h2>9. Complaints</h2>
<p>If you believe we have breached the Australian Privacy Principles, please contact us at [PRIVACY_EMAIL] in the first instance. If you're not satisfied with our response, you may lodge a complaint with the Office of the Australian Information Commissioner (OAIC) at oaic.gov.au.</p>

<h2>10. Changes to this policy</h2>
<p>We may update this policy from time to time. The current version will always be available on this page, with the "last updated" date shown above.</p>

<p><em>Tiro Mobile Tyres — ABN [ABN PLACEHOLDER]. This document is a complete operational draft; the bracketed placeholders should be reviewed and confirmed (ideally with legal advice) before relying on it commercially.</em></p>
HTML,
            ],
        );

        ContentPage::query()->updateOrCreate(
            ['type' => ContentPageType::Page, 'slug' => 'about-us'],
            [
                'title' => 'About Tiro Mobile Tyres',
                'status' => PageStatus::Published,
                'published_at' => now(),
                'body' => <<<'HTML'
<h2>Tyres, without the trip to the workshop</h2>
<p>Tiro exists because buying tyres shouldn't mean taking half a day off work to sit in a waiting room. We bring the tyre shop to you — to your home, your office car park, wherever your car actually is — and fit, balance, and dispose of your old tyres on the spot.</p>

<h2>How we're different</h2>
<p>Every price you see is the fitted price. No workshop markup you find out about at the counter, no "while you're here" upsells from a fitter you'll never see again. You search by your tyre size or your rego, pick a time that works for you, and that's the job — done at your door.</p>

<h2>Our fitters</h2>
<p>Our mobile fitting vans are fully equipped workshops on wheels, staffed by trained technicians who fit and balance tyres to the same standard as a fixed workshop — because that's exactly what they're trained to do, just without the fixed address.</p>

<h2>Where we operate</h2>
<p>We're expanding our serviced suburbs regularly — enter your postcode on the homepage to check whether we currently fit in your area.</p>
HTML,
            ],
        );

        ContentPage::query()->updateOrCreate(
            ['type' => ContentPageType::Page, 'slug' => 'contact'],
            [
                'title' => 'Contact us',
                'status' => PageStatus::Published,
                'published_at' => now(),
                'body' => <<<'HTML'
<h2>Get in touch</h2>
<p>For booking questions, use the live chat or your account's booking history for the fastest response. For anything else, reach us at:</p>
<p><strong>Email:</strong> [SUPPORT_EMAIL]<br><strong>Phone:</strong> [SUPPORT_PHONE] (Mon–Fri, [SUPPORT_HOURS])</p>
<h2>Price-match claims</h2>
<p>Already have a quote from another retailer? Submit a price-match claim from your account and we'll respond within one business day.</p>
<h2>Fleet &amp; business accounts</h2>
<p>Managing tyres for multiple vehicles? Contact us about a Tiro Fleet account for consolidated billing and priority scheduling.</p>
HTML,
            ],
        );
    }

    private function seedFaqs(): void
    {
        $faqs = [
            [
                'question' => 'Do I need to be home during the fitting?',
                'answer' => "No — as long as your vehicle is accessible and unlocked (or you've arranged access with us), our fitter can complete the job without you present. You'll get an SMS when the job's done.",
                'category' => 'booking',
            ],
            [
                'question' => 'How far in advance can I book?',
                'answer' => 'You can book same-day if a slot is available in your area, or up to [MAX_BOOKING_WINDOW_DAYS] days ahead.',
                'category' => 'booking',
            ],
            [
                'question' => 'What happens to my old tyres?',
                'answer' => 'We remove your old tyres and dispose of them responsibly through a licensed tyre recycler at no extra cost — it\'s included in every fitting.',
                'category' => 'service',
            ],
            [
                'question' => 'Is the price I see the final price?',
                'answer' => "Yes. The fitted price shown at booking includes fitting, balancing, and old-tyre disposal — there's nothing added on the day.",
                'category' => 'pricing',
            ],
            [
                'question' => 'Do you price-match?',
                'answer' => 'Yes — see our price guarantee for eligibility, or submit a claim from your account.',
                'category' => 'pricing',
            ],
        ];

        foreach ($faqs as $sortOrder => $faq) {
            Faq::query()->updateOrCreate(
                ['question' => $faq['question'], 'content_page_id' => null],
                [
                    'answer' => $faq['answer'],
                    'category' => $faq['category'],
                    'status' => PageStatus::Published,
                    'sort_order' => $sortOrder,
                ],
            );
        }
    }
}
