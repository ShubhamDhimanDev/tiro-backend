<?php

namespace Database\Seeders\Demo;

use Database\Seeders\DemoCatalogSeeder;

/**
 * Static copy and reference data for {@see DemoCatalogSeeder}.
 * DEMO/PLACEHOLDER content for development and design review only: realistic
 * enough to exercise the storefront, not approved marketing copy. Product
 * names are real-world tyre ranges used purely as plausible test data; all
 * prices, offers and review text are invented.
 */
final class DemoContent
{
    /**
     * @return list<array{title: string, slug: string, category: string, excerpt: string, body: string, months_ago: int}>
     */
    public static function blogPosts(): array
    {
        return [
            [
                'title' => 'How often should you replace your tyres?',
                'slug' => 'how-often-should-you-replace-your-tyres',
                'category' => 'Tyre care',
                'excerpt' => 'Tread depth, age and driving habits all matter. Here is how to tell when it is time.',
                'months_ago' => 1,
                'body' => '<p>Most tyres last somewhere between 40,000 and 60,000 kilometres, but mileage is only part of the story. Age, road conditions and how you drive all change the answer.</p><h2>Check the tread</h2><p>The legal minimum tread depth in Australia is 1.5 mm across the whole tread. Most tyre professionals suggest replacing at 3 mm, because wet-weather braking drops off quickly below that. Look for the tread wear indicators moulded into the grooves: when the tread is level with them, the tyre is done.</p><h2>Check the age</h2><p>Rubber hardens over time. Look for a four-digit date code on the sidewall (for example 2423 means week 24 of 2023). Tyres older than six years deserve a careful inspection, and ten years is a sensible upper limit even if the tread looks fine.</p><h2>Look for damage</h2><p>Bulges, cracks and cuts in the sidewall mean the tyre should be replaced straight away. A mobile fitter can inspect and swap them at your driveway.</p>',
            ],
            [
                'title' => 'Tyre pressure: the two-minute check that saves money',
                'slug' => 'tyre-pressure-two-minute-check',
                'category' => 'Tyre care',
                'excerpt' => 'Correct pressure improves safety, fuel economy and tyre life. Check it monthly.',
                'months_ago' => 2,
                'body' => '<p>Under-inflated tyres run hotter, wear faster on the shoulders and use more fuel. Over-inflated tyres wear in the centre and give a harsher ride.</p><h2>Find the right number</h2><p>The correct pressure is on the tyre placard, usually inside the driver door jamb or fuel flap. It is not the maximum pressure printed on the tyre sidewall.</p><h2>Check when the tyres are cold</h2><p>Check before you drive, or after the car has sat for a few hours. Add 2 to 4 psi if you carry a heavy load or tow.</p>',
            ],
            [
                'title' => 'Run-flat tyres explained',
                'slug' => 'run-flat-tyres-explained',
                'category' => 'Buying guide',
                'excerpt' => 'What run-flats are, how far they will take you after a puncture, and who should buy them.',
                'months_ago' => 3,
                'body' => '<p>A run-flat tyre has a reinforced sidewall that supports the vehicle for a limited distance after a loss of pressure, typically up to 80 km at no more than 80 km/h.</p><h2>Who needs them</h2><p>If your car came with run-flats and has no spare wheel, you should stay with run-flats. Check for the RFT, ZP, SSR or DSST marking on your current tyres.</p><h2>Trade-offs</h2><p>Run-flats usually ride a little firmer and cost more than standard tyres. Always replace like with like unless your vehicle manufacturer says otherwise.</p>',
            ],
            [
                'title' => 'Why wheel balancing matters',
                'slug' => 'why-wheel-balancing-matters',
                'category' => 'Tyre care',
                'excerpt' => 'A vibrating steering wheel at 100 km/h is usually a balance issue, and it is cheap to fix.',
                'months_ago' => 4,
                'body' => '<p>No tyre and wheel assembly is perfectly uniform. Balancing adds small weights so the wheel spins smoothly. Without it you may feel vibration through the steering wheel or seat, and tyres can wear unevenly.</p><p>Every fitting by our mobile technicians includes computer wheel balancing.</p>',
            ],
            [
                'title' => 'Summer road trip checklist for your tyres',
                'slug' => 'summer-road-trip-tyre-checklist',
                'category' => 'Safety',
                'excerpt' => 'Heat and heavy loads are tough on tyres. Run through this list before you leave.',
                'months_ago' => 5,
                'body' => '<p>Before a long drive, check pressures (including the spare), inspect the tread and sidewalls, and make sure your tyres are rated for the load you plan to carry.</p><ul><li>Set pressures to the loaded value on the placard.</li><li>Check the spare is inflated and the jack and wheel brace are in the car.</li><li>Replace any tyre with cracking or a tread depth under 3 mm.</li></ul>',
            ],
            [
                'title' => 'Mobile tyre fitting: what to expect on the day',
                'slug' => 'mobile-tyre-fitting-what-to-expect',
                'category' => 'How it works',
                'excerpt' => 'From the confirmation text to the final torque check, here is how a fitting works.',
                'months_ago' => 6,
                'body' => '<p>After you book, you receive a confirmation and a reminder. On the day our technician arrives in a fully equipped van, removes your old wheels, fits and balances the new tyres, tightens wheel nuts to the manufacturer torque, and takes the old tyres away for recycling.</p><p>You do not need to be home as long as the car is accessible. A typical four-tyre fitting takes about an hour.</p>',
            ],
        ];
    }

    /**
     * @return list<array{title: string, slug: string, category: string, excerpt: string, body: string, months_ago: int}>
     */
    public static function guides(): array
    {
        return [
            [
                'title' => 'How to read a tyre size',
                'slug' => 'how-to-read-a-tyre-size',
                'category' => 'Tyre basics',
                'excerpt' => 'Decode 205/55 R16 91V in thirty seconds.',
                'months_ago' => 2,
                'body' => '<h2>The numbers</h2><p>In 205/55 R16 91V, 205 is the section width in millimetres, 55 is the aspect ratio (sidewall height as a percentage of width), R means radial construction and 16 is the wheel diameter in inches.</p><h2>Load index and speed rating</h2><p>91 is the load index (615 kg per tyre) and V is the speed rating (up to 240 km/h). Never fit a tyre with a lower load index or speed rating than the vehicle placard calls for.</p>',
            ],
            [
                'title' => 'Choosing between premium, mid-range and budget tyres',
                'slug' => 'premium-mid-range-or-budget-tyres',
                'category' => 'Buying guide',
                'excerpt' => 'What you actually get for the extra dollars.',
                'months_ago' => 3,
                'body' => '<h2>Premium</h2><p>Leading brands invest heavily in wet braking, noise and tread life. They are the best choice for high-kilometre drivers and anyone who values the shortest stopping distances.</p><h2>Mid-range</h2><p>Strong all-rounders from established manufacturers, usually the best value for everyday driving.</p><h2>Budget</h2><p>Perfectly legal and safe for light use, low-kilometre cars and second vehicles, though they typically wear faster and are noisier.</p>',
            ],
            [
                'title' => 'Tyre types: highway, all-terrain, mud-terrain, performance and eco',
                'slug' => 'tyre-types-explained',
                'category' => 'Tyre basics',
                'excerpt' => 'Match the tyre to the way you really drive.',
                'months_ago' => 4,
                'body' => '<p>Highway tyres favour comfort and low noise. All-terrain tyres balance sealed-road manners with gravel grip. Mud-terrain tyres are built for serious off-road use and are noisier on the bitumen. Performance tyres maximise grip and steering response, and eco tyres reduce rolling resistance to save fuel.</p>',
            ],
            [
                'title' => 'Staggered fitments: when front and rear tyres differ',
                'slug' => 'staggered-tyre-fitments',
                'category' => 'Tyre basics',
                'excerpt' => 'Why some cars run wider tyres at the back.',
                'months_ago' => 5,
                'body' => '<p>Many rear-wheel-drive and performance cars use wider rear tyres. Because the front and rear sizes differ, tyres cannot be rotated front to back, and you need to order each axle separately.</p>',
            ],
        ];
    }

    /**
     * @return list<array{question: string, answer: string, category: string}>
     */
    public static function faqs(): array
    {
        return [
            ['question' => 'How does mobile tyre fitting work?', 'category' => 'booking', 'answer' => 'Choose your tyres, pick a fitting time and tell us where the car will be. Our technician arrives in a fully equipped van and fits, balances and recycles your old tyres on the spot.'],
            ['question' => 'How long does a fitting take?', 'category' => 'booking', 'answer' => 'Allow about an hour for a set of four tyres. Run-flat and larger 4x4 tyres can take a little longer.'],
            ['question' => 'What does the price include?', 'category' => 'pricing', 'answer' => 'The price shown includes the tyre, onsite fitting, wheel balancing, new valves and recycling of your old tyres, all GST inclusive.'],
            ['question' => 'What is the flexible booking discount?', 'category' => 'pricing', 'answer' => 'If you can be flexible about the arrival time on the day, you save on your fitting. We assign a real time slot and tell you the window.'],
            ['question' => 'Can I get a 4 for 3 deal?', 'category' => 'pricing', 'answer' => 'Selected brands run 4 for 3 offers: buy four tyres and the cheapest one is free. Look for the red 4 for 3 label, and see the offers page for current deals.'],
            ['question' => 'Do you fit run-flat tyres?', 'category' => 'service', 'answer' => 'Yes. Filter the results by run-flat and our technicians fit them with the right equipment.'],
            ['question' => 'How do I find my tyre size?', 'category' => 'tyres', 'answer' => 'The size is printed on the sidewall of your current tyres, for example 205/55 R16. You can also search by your number plate or by your car make and model.'],
            ['question' => 'What is the difference between premium, mid-range and budget tyres?', 'category' => 'tyres', 'answer' => 'Premium tyres lead on wet braking, noise and tread life. Mid-range tyres are strong all-rounders. Budget tyres suit light use and tight budgets.'],
            ['question' => 'Can I change or cancel my booking?', 'category' => 'booking', 'answer' => 'Yes. Use the link in your confirmation message to reschedule or cancel. Cancelling close to the appointment time may incur a fee, which is shown before you confirm.'],
            ['question' => 'Do I need to be home during the fitting?', 'category' => 'booking', 'answer' => 'No, as long as we can access the vehicle. You will get a message when the job is complete.'],
            ['question' => 'What areas do you service?', 'category' => 'service', 'answer' => 'We service major metropolitan areas and are growing. Enter your suburb or postcode on the locations page to check, or register your interest if we are not there yet.'],
            ['question' => 'What payment methods do you accept?', 'category' => 'pricing', 'answer' => 'Major credit and debit cards, plus supported digital wallets. Payment is taken securely when you place your order.'],
        ];
    }

    /**
     * @return list<array{author: string, rating: int, body: string, reply: string|null}>
     */
    public static function reviews(): array
    {
        return [
            ['author' => 'Michael R.', 'rating' => 5, 'body' => 'Booked at lunchtime, tyres fitted in my driveway that afternoon. Technician was friendly and tidy. Will use again.', 'reply' => 'Thanks Michael, great to hear it worked for you.'],
            ['author' => 'Sarah T.', 'rating' => 5, 'body' => 'Price was exactly what the website said, no surprises. Four new Michelins fitted while I worked from home.', 'reply' => null],
            ['author' => 'David K.', 'rating' => 5, 'body' => 'Super convenient. Got a text when the van was on the way and the whole job took about an hour.', 'reply' => null],
            ['author' => 'Priya N.', 'rating' => 4, 'body' => 'Good service and fair price. Arrived a little late but called ahead to let me know.', 'reply' => 'Thanks Priya, we appreciate the feedback on timing.'],
            ['author' => 'Tom B.', 'rating' => 5, 'body' => 'Had a flat on a Sunday evening and they sorted a replacement first thing Monday. Brilliant.', 'reply' => null],
            ['author' => 'Jessica L.', 'rating' => 5, 'body' => 'Loved that I did not have to take the car anywhere. Easy to order online, easy to pick a time.', 'reply' => null],
            ['author' => 'Andrew W.', 'rating' => 5, 'body' => 'Fitted four Bridgestones on my Hilux. Neat work and they took the old tyres away.', 'reply' => null],
            ['author' => 'Emma H.', 'rating' => 4, 'body' => 'Great experience overall. The flexible time discount was a nice bonus.', 'reply' => null],
            ['author' => 'Chris M.', 'rating' => 5, 'body' => 'Second time using them. Just as smooth as the first.', 'reply' => null],
            ['author' => 'Linda S.', 'rating' => 5, 'body' => 'Helpful on the phone when I was not sure which size I needed. Fitted the next day.', 'reply' => 'Thanks Linda, happy to help.'],
            ['author' => 'Ben C.', 'rating' => 3, 'body' => 'Tyres are great but I had to reschedule once because of rain. Support sorted it quickly.', 'reply' => 'Sorry about the weather delay, Ben. Thanks for your patience.'],
            ['author' => 'Natalie P.', 'rating' => 5, 'body' => 'Excellent. Fitted two tyres in my work car park during a meeting.', 'reply' => null],
            ['author' => 'Rohan G.', 'rating' => 5, 'body' => 'Cheaper than the local shop and they came to me. No-brainer.', 'reply' => null],
            ['author' => 'Karen J.', 'rating' => 4, 'body' => 'Professional and careful with the wheels. Would recommend.', 'reply' => null],
            ['author' => 'Josh F.', 'rating' => 5, 'body' => 'Quick, clean and the technician explained the tread wear on my other tyres.', 'reply' => null],
            ['author' => 'Mei L.', 'rating' => 5, 'body' => 'Great communication from booking to completion.', 'reply' => null],
            ['author' => 'Paul D.', 'rating' => 2, 'body' => 'Fitting itself was fine but the arrival window was wider than I expected.', 'reply' => 'Thanks Paul. We are working on tighter arrival windows.'],
            ['author' => 'Alicia V.', 'rating' => 5, 'body' => 'Five stars. Easy online checkout and a very polite technician.', 'reply' => null],
            ['author' => 'Sam O.', 'rating' => 5, 'body' => 'Good range of budget options for my old runabout. Fitted in under an hour.', 'reply' => null],
            ['author' => 'Hannah Q.', 'rating' => 5, 'body' => 'Did the 4 for 3 deal on my SUV. Saved a heap.', 'reply' => null],
            ['author' => 'George A.', 'rating' => 4, 'body' => 'Smooth process. Would like more evening slots.', 'reply' => null],
            ['author' => 'Fiona E.', 'rating' => 5, 'body' => 'Super handy for a busy household. Thank you.', 'reply' => null],
            ['author' => 'Ian Y.', 'rating' => 5, 'body' => 'Great value and the van was spotless.', 'reply' => null],
            ['author' => 'Zoe K.', 'rating' => 5, 'body' => 'Quick quote, quick fitting, no fuss.', 'reply' => null],
        ];
    }
}
