# Appointment Facilitator Module

## Overview
The **Appointment Facilitator** module extends the standard appointment system to support:

- Appointments with **time ranges** instead of fixed half-hour slots.
- A **capacity system** that controls whether multiple attendees can join an appointment.
- A **Join CTA (button)** that appears only when more than one person can attend, unless overridden by settings.
- Configurable capacity rules based on:
  - Appointment node (`field_appointment_capacity`)
  - Selected badge(s) (`field_badge_capacity` on taxonomy terms)
  - Facilitator profile (`field_coordinator_capacity` on Profile type: coordinator)

By default, appointments remain single-attendee (as before). Capacity must be explicitly raised for the Join CTA to appear.

---

## Installation

1. Copy the module into your project:
   ```bash
   unzip appointment_facilitator_module_v5.zip -d web/modules/custom/
Enable the module:

bash
Copy code
drush en appointment_facilitator -y
Run database updates to install new fields:

bash
Copy code
drush updb -y
Clear caches:

bash
Copy code
drush cr
The module will then add the following fields automatically:

Appointments (content type):

field_appointment_timerange (Smart Date range if available)

field_appointment_capacity

field_appointment_attendees

Badges (taxonomy):

field_badge_capacity (“Badge Max Attendees”)

Coordinator Profile (if present):

field_coordinator_capacity (“Max Attendees Per Appointment”)

Configuration
Settings page
Go to:
Configuration → People → Appointment Facilitator
URL: /admin/config/people/appointment-facilitator

Options:

Always show Join button – Forces the Join CTA to display even when capacity is set to 1.

Capacity rules
The effective capacity is determined by the minimum of:

Appointment node capacity (field_appointment_capacity)

Badge capacity (field_badge_capacity)

Facilitator capacity (field_coordinator_capacity)

If no values are set, default is 1 (single attendee).

Usage
When editing a badge (taxonomy term in Badges vocabulary), set Badge Max Attendees to allow multiple people to join appointments requiring that badge.

When editing a facilitator profile (Profile type: coordinator), set Max Attendees Per Appointment if that facilitator allows multiple learners.

When creating/editing an appointment, set Capacity if you want to override or enforce a specific limit.

The Join button on appointment nodes will only appear when the effective capacity > 1, unless overridden by the settings page.

Related Admin Pages
Appointment Facilitator Settings
/admin/config/people/appointment-facilitator

Appointment Content Type (manage fields, displays, etc.)
/admin/structure/types/manage/appointment

Badge Vocabulary (manage fields, capacity, etc.)
/admin/structure/taxonomy/manage/badges/overview/fields

Facilitator Profile Type (manage fields)
/admin/config/people/profile-types/manage/coordinator/fields
