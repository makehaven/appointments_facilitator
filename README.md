# Appointment Facilitator Module

## Overview

The **Appointment Facilitator** module provides a flexible appointment system for Drupal. Its core feature is a dynamic capacity system that controls how many attendees can join an appointment.

Instead of being set manually on each appointment, capacity is calculated automatically based on the tools ("Badges") required for the appointment and the personal limits of the facilitator ("Host").

## Features

- **Dynamic Capacity:** Appointment capacity is automatically calculated as the **minimum** of the capacities defined on:
  - **Badges:** A configurable taxonomy vocabulary representing tools or topics.
  - **Facilitator Profile:** A configurable Profile entity for the appointment host.
- **Join Workflow:** A "Join" button is shown to logged-in users on appointments. It only appears if the calculated capacity is greater than one, or if the "always show" setting is enabled. The system prevents users from joining a full appointment.
- **Configurable Entities:** You can specify the machine names for the Badges vocabulary and the Facilitator Profile bundle, allowing the module to integrate with your existing site structure.
- **Automated Setup:** The module automatically creates and configures all required fields on installation and update, including fields on your specified vocabulary and profile type.
- **Status Report Integration:** The module adds warnings to the Drupal status report if required fields, vocabularies, or profile types are missing, helping administrators diagnose configuration issues.
- **Smart Date Integration:** Uses the Smart Date module for the appointment time range field if it is available, falling back gracefully to the core Date Range field.

## Installation

1.  Install the module as you would any other Drupal module. If using Composer:
    ```bash
    composer require drupal/appointment_facilitator
    ```
2.  Enable the module through the Drupal UI or with Drush:
    ```bash
    drush en appointment_facilitator -y
    ```
3.  Run database updates to allow the module to create its required fields:
    ```bash
    drush updb -y
    ```
4.  Clear caches:
    ```bash
    drush cr
    ```

## Configuration

Before the module can calculate capacity, you need to have the source entities (a vocabulary for Badges and a Profile type for facilitators) in place.

**1. Prerequisites:**

-   **Badges Vocabulary:** You need a taxonomy vocabulary to represent the tools or topics for an appointment. If you don't have one, create one at `/admin/structure/taxonomy/add`. The default machine name is `badges`.
-   **Facilitator Profile Type:** If you use the Profile module for facilitator information, you need a profile type. If you don't have one, create one at `/admin/config/people/profile-types/add`. The default machine name is `coordinator`.

**2. Module Settings:**

-   Navigate to the settings page at **Configuration → People → Appointment Facilitator** (`/admin/config/people/appointment-facilitator`).
-   Configure the following settings:
    -   **Always show Join button:** (Checkbox) Forces the Join CTA to display even when capacity is 1. Useful for testing or if you always want the "Seats left" info to be visible.
    -   **Badges vocabulary machine name:** Enter the machine name of your badges vocabulary (e.g., `badges`).
    -   **Facilitator profile bundle machine name:** Enter the machine name of your facilitator profile type (e.g., `coordinator`).

**3. Set Capacity Values:**

-   The module will have automatically added capacity fields to your configured vocabulary and profile type.
-   **To set a badge's capacity:** Edit a term in your badges vocabulary (e.g., at `/admin/structure/taxonomy/manage/badges/overview`) and set the value for the **"Badge Max Attendees"** field.
-   **To set a facilitator's capacity:** Edit the user's facilitator profile (e.g., from their user page) and set the value for the **"Max Attendees Per Appointment"** field.

## How It Works: The Capacity Calculation

The effective capacity for an appointment is the **minimum** of all capacity values found from the following sources:
- The "Badge Max Attendees" value for *each* badge referenced on the appointment.
- The "Max Attendees Per Appointment" value from the facilitator's profile.

Any empty or zero values are ignored. If no capacity values are found across any of the sources, the capacity defaults to **1**.

**Example:**
- An appointment requires "Badge A" (Capacity: 5) and "Badge B" (Capacity: 10).
- The facilitator's personal capacity is 3.
- The effective capacity for this appointment will be **3** (`min(5, 10, 3)`).

## Future Development

This module provides a solid foundation for a flexible appointment system. Future development could build on this structure in several ways:

-   **Reusable Time Slots:** The current implementation uses a simple date field for scheduling. This could be enhanced by moving to a system of pre-defined, reusable time slots that facilitators can claim, providing a more structured scheduling experience.
-   **Advanced Join Workflow:** The join workflow could be expanded with more features, such as:
    -   Automated waitlists for full appointments.
    -   Email notifications for joining, reminders, and cancellations.
    -   Calendar (iCal) integrations for attendees.
-   **Permissions:** The join workflow could be tied into a more granular permission system.
