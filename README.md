# Genesis Reservations

## Overview
A WordPress plugin that adds an event reservation system via shortcode. Visitors can sign up for events directly on any page, and admins can view and manage all RSVPs from a dedicated admin panel.

## Problem It Solves
- Setting up event registrations on WordPress typically requires heavy plugins like The Events Calendar or paid solutions
- For simple one-off events, all that is needed is a form, a capacity limit, and a list of sign-ups
- Target users: WordPress site owners who run occasional events and need a lightweight, no-cost registration tool

## Use Cases
1. A club posts an upcoming meeting — the shortcode renders a sign-up form with the event details and remaining spots shown inline
2. An organizer opens the WordPress admin to see a full list of registrants with their names and emails, ready to export or copy
3. An event reaches capacity — the form automatically stops accepting new reservations and shows a "fully booked" message

## Key Features
- **Flexible shortcode** — configure event name, time, place, description, and max capacity per instance
- **Capacity enforcement** — registrations are automatically closed when the limit is reached
- **Admin panel** — view all reservations with registrant name, email, and timestamp; delete entries as needed
- **No third-party dependencies** — single PHP file, custom DB table, no Composer required

## Tech Stack
- PHP 7.2+
- WordPress 5.0+
- MySQL (via `$wpdb`)

## Getting Started

1. Copy the `genesis-wp-plugin` folder to `wp-content/plugins/`
2. Activate **Genesis Reservations** in the WordPress admin under **Plugins**
3. Add the shortcode to any page or post:

```
[reservation event="Summer Meetup" time="6:00 PM" place="City Hall" description="Join us for our annual meetup." max="50"]
```

| Parameter | Description |
|---|---|
| `event` | Event name shown on the form |
| `time` | Event time (free-form string) |
| `place` | Venue or location |
| `description` | Short description shown above the form |
| `max` | Maximum number of reservations accepted |

Submitted reservations appear under **Genesis Reservations** in the WordPress admin sidebar.
