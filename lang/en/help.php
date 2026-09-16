<?php

// The "How to use" guide. Every section is {title, intro?, steps?, items?} so the
// same view renders both languages; keep the structure identical in lang/ar/help.php.

return [

    'title' => 'How to use',
    'heading' => 'How to use GScraper',
    'intro' => 'What every button does, in the order you will meet them. Each section matches a page in the app.',
    'contents' => 'On this page',
    'back_to_app' => 'Back to the app',
    'admin_only' => 'Admins only',

    'sections' => [

        'start' => [
            'title' => 'In short',
            'intro' => 'GScraper searches Google Maps for a type of business in a place, throws away the ones that are closed or dormant, finds a way to contact the rest, and scores how much they need what the agency sells.',
            'steps' => [
                'A worker — the GScraper program running on an office PC with Chrome — does the searching. The website only gives it jobs and stores what comes back, so a search only starts while that PC is on and connected.',
                'You type one or more searches and press Find leads. The run is queued.',
                'The run page shows live progress. When it finishes you get three lists: leads you can reach, businesses you cannot reach, and businesses that look inactive.',
                'You work through the leads, or export them as CSV.',
            ],
        ],

        'search' => [
            'title' => 'Find leads — the search form',
            'intro' => 'This is the main page. Every field below is on it.',
            'items' => [
                'Searches' => 'One Google Maps search per line, exactly as you would type it into Maps: “dentists in Nasr City”. Be specific about the area — a whole city returns the same big names everyone already called. Lines starting with # are ignored, so you can leave notes to yourself. Your text is kept in the browser if you leave the page without sending it.',
                '…or upload a .txt file' => 'A plain text file with one search per line, for when you have prepared a long list elsewhere. It is merged with whatever is in the box. The file is read once and never stored.',
                'Results per search' => 'How many businesses to open on Maps for each line. Twenty is a sensible start. This is the main thing that decides how long a run takes and how much of the daily budget it eats.',
                'Active within (months)' => 'How recent the proof of life has to be. A business passes if it has a review or an owner reply newer than this. Eleven months is the default: long enough to keep seasonal businesses, short enough to drop the dead ones.',
                'Phone calls count as a way to reach a lead' => 'Off by default. When off, a business with nothing but a phone number is dropped as unreachable (tier D). Turn it on if someone on your side will actually call or text these numbers — those businesses then come back as tier-B leads. WhatsApp always counts as reachable whether this is on or not, because it is written contact.',
                'Skip Facebook/Instagram checks (faster)' => 'Skips looking up social profiles. The run is quicker but you lose the Instagram and Messenger channels, so more businesses land in the unreachable list.',
                'Keep businesses with no reviews' => 'Normally a business with no dated reviews cannot be proven active and is dropped. Tick this to keep them — useful for brand-new businesses, but expect more dead ends.',
                'Find leads' => 'Queues the run and opens its page. Nothing is contacted yet; the worker picks the job up within a few seconds if it is online.',
                'The line under the button' => 'An estimate: how many searches it counted and the most businesses they could open. If that is more than the connection has left today, it tells you where the worker will stop.',
            ],
        ],

        'worker' => [
            'title' => 'Workers and the daily budget',
            'intro' => 'The two cards under the search form tell you whether a search can run right now, and why not when it cannot.',
            'items' => [
                'Online / Offline' => 'Online means the worker PC reported in within the last minute or so. Offline means it is switched off, asleep, or has no internet. Queued searches simply wait; nothing is lost.',
                'Worker is cooling down' => 'A deliberate pause after a run, so the pattern of traffic looks human. The countdown shows when searching resumes.',
                'Google blocked scraping' => 'Google showed a captcha or hid reviews on that connection. Scraping stops for 24 hours to let it settle. Signing in again does not lift it, and neither does restarting the PC.',
                'Daily budget' => 'How many businesses this internet connection may open on Maps in a rolling 24 hours, counted by the server rather than by the PC. Every worker in the same office shares one budget, because Google counts per connection. The bar shows what is used, and each business frees its slot 24 hours after it was scraped.',
                'Scraping paused for this connection' => 'The budget is spent. Searches wait for the countdown; re-process runs keep working the whole time, because they never touch Google. A worker on a genuinely different network — another office, a phone hotspot — has its own separate budget.',
            ],
        ],

        'run' => [
            'title' => 'The run page',
            'intro' => 'Everything about one search: what is happening now, and the results when it is done.',
            'items' => [
                'Live progress' => 'While a worker is on the job the page updates itself every few seconds: the phase it is in, how many businesses are active, how many were dropped, the tier mix and the hot count. The black box underneath is the worker\'s log, the same lines it prints on the PC.',
                'Stop' => 'Asks the worker to stop. It finishes the business it is on, saves everything collected so far, and marks the run stopped. Nothing already scraped is lost, and the saved places can be re-processed later.',
                'Re-process' => 'Runs the whole pipeline again — activity checks, contact hunting, website audit, scoring — on the places this run already saved. It never contacts Google Maps and never touches the daily budget, so it is free and always available. Use it after a run failed halfway, or when the scoring rules change. It creates a new run and leaves the original alone.',
                'Delete' => 'Removes the run and all of its results, permanently. A run that is still working has to be stopped first.',
                'Leads CSV / Unreachable CSV / Inactive CSV' => 'Downloads that list as a spreadsheet file. The columns are the full record, not just what the table shows, and the column headings stay in English so the file opens the same way for everyone.',
                'Leads / Unreachable / Inactive tabs' => 'Leads are the businesses you can reach in writing. Unreachable are active businesses with no usable channel — worth a glance, because a search that produces many of them is wasting budget. Inactive are the ones dropped for looking closed or dormant.',
                'Filters and sorting' => 'Narrow the leads by tier or priority, search within the results, and sort by score, name, reviews or rating. The filters are in the address bar, so a filtered view can be bookmarked or sent to a colleague.',
                'Clicking a row' => 'Opens the detail panel: every contact channel found and where it was found, why the business scored what it did, the website and marketing audit, and the evidence that it is still active. Click again to close it.',
            ],
        ],

        'reading' => [
            'title' => 'Reading the results',
            'intro' => 'Four things describe every lead: how you can reach it, how urgent it is, where it is weak, and what to offer.',
            'items' => [
                'Tier A' => 'An email address or a WhatsApp number was found. Write to them directly; links are fine.',
                'Tier B' => 'A contact form, an Instagram account, or — when you turned that setting on — a phone number. Reachable, but the first message has to survive without a link.',
                'Tier C' => 'A Facebook Page and nothing else. Messenger often strips or buries links, so the opener has to stand on its own.',
                'Tier D' => 'No way to reach them in writing. Not scored, not for outreach. They are listed only so you can see what a search costs you.',
                'Hot / Warm / Cold' => 'Priority by score: hot is 50 and above, warm 30 to 49. A high score means many gaps the agency can fill, not that the business is large.',
                'Problems' => 'The score split across six areas: website and UX, conversion and lead capture, paid media and tracking, social presence and content, chat and response, and brand and listing consistency. The bar shows how much of that area\'s maximum the business lost. Areas the agency does not sell can be switched off in the worker\'s configuration, and then stop counting.',
                'Pitch' => 'The packages that fit the gaps found — a new website, a landing page, Meta or Google campaigns, Reels, a chatbot, community management, and so on. Treat it as a starting point for the first message, not a quote.',
                'Website column' => 'What the site actually is: no website, a Facebook page used as one, a site that is down, parked, or blocked to automated visitors, or a working site. “Blocks automated checks” means nothing could be read, not that the site is bad.',
            ],
        ],

        'leads' => [
            'title' => 'The Leads page',
            'intro' => 'Every reachable lead from every run in one list, for working through rather than reviewing a single search.',
            'items' => [
                'Search boxes' => 'The first searches inside the results — name, category, email, address. The second matches the exact Google Maps search a lead came from.',
                'Newest only / Show all' => 'By default a business checked in several runs appears once, with its most recent result. Show all reveals the older checks too, which is how you see whether a business has fixed something since.',
                'Export CSV' => 'Exports exactly what the current filters show, so you can hand a colleague a specific slice.',
            ],
        ],

        'insights' => [
            'title' => 'Search insights',
            'intro' => 'Which of your searches are worth repeating.',
            'items' => [
                'The table' => 'One row per search across every run: how many businesses it found, how many were inactive, the tier mix, and how many were reachable. A search where most rows are inactive or tier D is spending budget for nothing — narrow the area or change the category.',
                'Count each business once / every result' => 'Switches between counting a repeatedly-checked business once and counting every check. Count once to judge a search; count everything to see total effort.',
                'The search name' => 'Click it to open the Leads page filtered to that exact search.',
            ],
        ],

        'import' => [
            'title' => 'Import',
            'intro' => 'Brings results from the old desktop GMSCraper into this app.',
            'items' => [
                'leads.json' => 'Required. Pick it from a folder inside the desktop app\'s output directory. The businesses appear as a normal run you can filter and export.',
                'raw-places.jsonl' => 'Optional but worth including: it carries the raw Maps records, which is what makes Re-process possible on the imported run later.',
            ],
        ],

        'account' => [
            'title' => 'Your account, users and workers',
            'items' => [
                'Account' => 'Your name, email, role, and where you change your password. Changing it needs your current one.',
                'Users' => 'Admins add accounts, change roles, and deactivate people. A deactivated account is signed out immediately and cannot sign back in; nothing it created is deleted. The last remaining admin cannot be demoted or deactivated, so nobody can lock everyone out.',
                'Workers' => 'Admins create one token per PC that will scrape. The token is shown once, at creation — copy it then, because it is stored only as a hash and cannot be shown again. Revoke stops that PC immediately; if a token leaks, revoke it and create a new one.',
                'Setup guide' => 'The six steps for turning a Windows PC into a worker. The longer version, with troubleshooting, is in the project\'s docs folder.',
            ],
        ],

        'language' => [
            'title' => 'Language and this guide',
            'items' => [
                'العربية / English' => 'The button in the top bar switches the whole interface between Arabic and English. The choice is remembered on this device — for a year, and through signing out — and it is yours alone: it changes nothing for your colleagues.',
                'What stays in English' => 'Results already collected keep the wording the worker saved: the activity evidence, the reason behind each score, the worker log, and CSV column headings. Tiers, priorities, packages, website status and the rest of the interface all follow the language you pick.',
                'How to use' => 'This page. It follows the language you are in, and every section has its own link if you want to send someone straight to one answer.',
            ],
        ],
    ],
];
